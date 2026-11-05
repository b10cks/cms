<?php

namespace App\Services\Ai\Changesets;

use App\Models\Space\AiChangesetItem;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\Content;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Ai\Run\ToolError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Resolves a scope (explicit IDs, folder, block, locale gaps) to entries.
 * `find_entries` and the preflight use the same resolution, so a confirmed
 * estimate covers exactly the entries the run then works on.
 *
 * Operations use the recursive variant ({@see count()}, {@see each()}): a
 * folder or entry with all its descendants, walked in stable chunks with SQL
 * filters only, so the count and the walk always agree.
 */
class EntryScope
{
    public const MAX_ENTRIES = 500;

    /** SQL filters a recursive scope accepts. */
    public const RECURSIVE_FILTERS = ['block', 'language', 'missing_locale', 'query', 'data_source'];

    private const CHUNK = 500;

    /** Rough characters per token for estimates. */
    private const CHARS_PER_TOKEN = 4;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: string, name: string, tokens: int}>
     */
    public function resolve(string $type, array $filters, int $limit = self::MAX_ENTRIES): array
    {
        return $this->fetch($type, $filters, max(1, min(self::MAX_ENTRIES, $limit)));
    }

    /**
     * Whether a flat scope matches more entries than one run may take. Such a
     * scope needs an operation; {@see resolve()} would cut it at MAX_ENTRIES.
     *
     * @param  array<string, mixed>  $filters
     */
    public function overflows(string $type, array $filters): bool
    {
        return \count($this->fetch($type, $filters, self::MAX_ENTRIES + 1)) > self::MAX_ENTRIES;
    }

    /**
     * Entries of a recursive scope, without loading them.
     *
     * @param  array{ids?: list<string>|null, root_id?: string|null, recursive?: bool, filters?: array<string, mixed>}  $spec
     */
    public function count(string $type, array $spec, bool $canonicalOnly = false): int
    {
        return array_sum(array_map(fn (Builder $query) => $query->count(), $this->partitions($type, $spec, $canonicalOnly)));
    }

    /**
     * Walk a recursive scope in chunks ordered by ID; every entry comes once.
     *
     * @param  array{ids?: list<string>|null, root_id?: string|null, recursive?: bool, filters?: array<string, mixed>}  $spec
     * @param  callable(Collection<int, Model>): void  $each
     */
    public function each(string $type, array $spec, callable $each, bool $canonicalOnly = false): void
    {
        foreach ($this->partitions($type, $spec, $canonicalOnly) as $query) {
            $query->chunkById(self::CHUNK, fn (Collection $chunk) => $each($chunk));
        }
    }

    /**
     * ID, label, rough source tokens, group (block slug, file family) and
     * version of one entry from {@see each()}.
     *
     * @return array{id: string, name: string, tokens: int, group: string|null, version_id: string|null}
     */
    public function describe(Model $entry): array
    {
        return match (true) {
            $entry instanceof Content => [
                'id' => $entry->id,
                'name' => (string) $entry->name,
                'tokens' => $this->tokens($entry->current_version?->content ?? []),
                'group' => $entry->block?->slug ?? 'folder',
                'version_id' => $entry->current_version_id,
            ],
            $entry instanceof Asset => [
                'id' => $entry->id,
                'name' => (string) $entry->filename,
                'tokens' => $this->tokens(data_get($entry->data, 'fields', [])),
                'group' => self::fileFamily((string) $entry->mime_type),
                'version_id' => null,
            ],
            $entry instanceof DataEntry => [
                'id' => $entry->id,
                'name' => (string) $entry->key,
                'tokens' => $this->tokens([$entry->value, $entry->dimensions]),
                'group' => null,
                'version_id' => null,
            ],
            default => throw new \LogicException('Unknown scope entry '.$entry::class),
        };
    }

    public static function fileFamily(string $mime): string
    {
        $mime = strtolower($mime);

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf', str_starts_with($mime, 'text/'), str_contains($mime, 'document'), str_contains($mime, 'msword'),
            str_contains($mime, 'spreadsheet'), str_contains($mime, 'presentation') => 'document',
            default => 'other',
        };
    }

    /**
     * One query per chunk of the scope; together they cover it exactly once.
     * A root is a parent: its children count (all descendants when
     * recursive), like the flat `parent_id` and `folder_id` filters. Explicit
     * IDs count themselves, plus their descendants when recursive. No root
     * and no IDs covers the space.
     *
     * @param  array<string, mixed>  $spec
     * @return list<Builder<Model>>
     */
    private function partitions(string $type, array $spec, bool $canonicalOnly): array
    {
        $ids = isset($spec['ids']) && \is_array($spec['ids']) ? array_values(array_filter($spec['ids'], 'is_string')) : null;
        $root = \is_string($spec['root_id'] ?? null) && $spec['root_id'] !== '' ? $spec['root_id'] : null;
        $recursive = (bool) ($spec['recursive'] ?? true);
        $filters = array_intersect_key((array) ($spec['filters'] ?? []), array_flip(self::RECURSIVE_FILTERS));
        $query = trim((string) ($filters['query'] ?? ''));
        $like = '%'.addcslashes($query, '%_\\').'%';

        return match ($type) {
            AiChangesetItem::TARGET_CONTENT => $this->chunked(
                $ids !== null || $root !== null ? $this->contentIds($ids, $root, $recursive) : null,
                'id',
                fn () => Content::query()
                    ->with(['current_version', 'block:id,slug'])
                    ->when($canonicalOnly, fn ($q) => $q->whereNull('i18n_parent_id'))
                    ->when(\is_string($filters['missing_locale'] ?? null), fn ($q) => $q
                        ->whereNull('i18n_parent_id')
                        ->whereDoesntHave('i18n_children', fn ($c) => $c->where('language_iso', $filters['missing_locale'])))
                    ->when($query !== '', fn ($q) => $q->where('name', 'like', $like))
                    ->when(\is_string($filters['block'] ?? null), fn ($q) => $q->whereHas('block', fn ($b) => $b->where('slug', $filters['block'])))
                    ->when(\is_string($filters['language'] ?? null), fn ($q) => $q->where('language_iso', $filters['language'])),
            ),
            AiChangesetItem::TARGET_ASSET => $ids !== null
                ? $this->chunked($ids, 'id', fn () => $this->assetQuery($query, $like))
                : ($root === null && $recursive
                    ? [$this->assetQuery($query, $like)]
                    : ($root === null
                        ? [$this->assetQuery($query, $like)->whereNull('folder_id')]
                        : $this->chunked($recursive ? $this->folderTree($root) : [$root], 'folder_id', fn () => $this->assetQuery($query, $like)))),
            AiChangesetItem::TARGET_DATA_ENTRY => $this->dataEntryPartitions($ids, $filters, $query, $like),
            default => throw new ToolError('target_type must be one of: '.implode(', ', ChangeTargets::TYPES).'.'),
        };
    }

    /**
     * @param  list<string>|null  $values  null means no restriction
     * @param  callable(): Builder<Model>  $base
     * @return list<Builder<Model>>
     */
    private function chunked(?array $values, string $column, callable $base): array
    {
        if ($values === null) {
            return [$base()];
        }

        return array_map(fn (array $chunk) => $base()->whereIn($column, $chunk), array_chunk(array_values(array_unique($values)), self::CHUNK));
    }

    private function assetQuery(string $query, string $like): Builder
    {
        return Asset::query()->when($query !== '', fn ($q) => $q->where('filename', 'like', $like));
    }

    /**
     * @param  list<string>|null  $ids
     * @param  array<string, mixed>  $filters
     * @return list<Builder<Model>>
     */
    private function dataEntryPartitions(?array $ids, array $filters, string $query, string $like): array
    {
        $source = $filters['data_source'] ?? null;
        $sourceId = \is_string($source) ? DataSource::query()->where('id', $source)->orWhere('slug', $source)->value('id') : null;

        if ($ids === null && $sourceId === null) {
            throw new ToolError('Pass data_source (ID or slug) or ids to find data entries.');
        }

        return $this->chunked($ids, 'id', fn () => DataEntry::query()
            ->when($sourceId !== null, fn ($q) => $q->where('data_source_id', $sourceId))
            ->when($query !== '', fn ($q) => $q->where('key', 'like', $like)));
    }

    /**
     * @param  list<string>|null  $ids
     * @return list<string>
     */
    private function contentIds(?array $ids, ?string $root, bool $recursive): array
    {
        $children = fn (array $parents) => Content::query()->whereIn('parent_id', $parents)->pluck('id')->all();

        if ($ids !== null) {
            return $recursive ? $this->descendants($ids, $children, PHP_INT_MAX) : $ids;
        }

        return array_values(array_diff($this->descendants([(string) $root], $children, $recursive ? PHP_INT_MAX : 1), [$root]));
    }

    /**
     * An asset folder and all its descendants.
     *
     * @return list<string>
     */
    public function folderTree(string $root): array
    {
        return $this->descendants([$root], fn (array $parents) => AssetFolder::query()->whereIn('parent_id', $parents)->pluck('id')->all(), PHP_INT_MAX);
    }

    /**
     * Breadth-first walk; IDs already seen are not walked twice, so a broken
     * parent cycle cannot loop.
     *
     * @param  list<string>  $roots
     * @param  callable(list<string>): list<string>  $children
     * @return list<string>
     */
    private function descendants(array $roots, callable $children, int $depth): array
    {
        $seen = array_fill_keys($roots, true);
        $frontier = $roots;

        for ($level = 0; $frontier !== [] && $level < $depth; $level++) {
            $next = [];
            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                foreach ($children($chunk) as $id) {
                    if (! isset($seen[$id])) {
                        $seen[$id] = true;
                        $next[] = $id;
                    }
                }
            }
            $frontier = $next;
        }

        return array_map('strval', array_keys($seen));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: string, name: string, tokens: int}>
     */
    private function fetch(string $type, array $filters, int $limit): array
    {
        $ids = isset($filters['ids']) && \is_array($filters['ids']) ? array_values(array_filter($filters['ids'], 'is_string')) : null;
        $query = trim((string) ($filters['query'] ?? ''));

        $entries = match ($type) {
            AiChangesetItem::TARGET_CONTENT => $this->contents($filters, $ids, $query, $limit),
            AiChangesetItem::TARGET_DATA_ENTRY => $this->dataEntries($filters, $ids, $query, $limit),
            AiChangesetItem::TARGET_ASSET => $this->assets($filters, $ids, $query, $limit),
            default => throw new ToolError('target_type must be one of: '.implode(', ', ChangeTargets::TYPES).'.'),
        };

        return $entries->take($limit)->values()->all();
    }

    /**
     * @return Collection<int, array{id: string, name: string, tokens: int}>
     */
    private function contents(array $filters, ?array $ids, string $query, int $limit): Collection
    {
        $builder = Content::query()
            ->with('current_version')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($query !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->when(\is_string($filters['block'] ?? null), fn ($q) => $q->whereHas('block', fn ($b) => $b->where('slug', $filters['block'])))
            ->when(\is_string($filters['parent_id'] ?? null), fn ($q) => $q->where('parent_id', $filters['parent_id']))
            ->when(\is_string($filters['language'] ?? null), fn ($q) => $q->where('language_iso', $filters['language']))
            ->when(\is_string($filters['missing_locale'] ?? null), fn ($q) => $q
                ->whereNull('i18n_parent_id')
                ->whereDoesntHave('i18n_children', fn ($c) => $c->where('language_iso', $filters['missing_locale'])));

        return $builder->orderBy('name')->orderBy('id')->limit($limit)->get()
            ->map(fn (Content $content) => [
                'id' => $content->id,
                'name' => (string) $content->name,
                'tokens' => $this->tokens($content->current_version?->content ?? []),
            ]);
    }

    /**
     * @return Collection<int, array{id: string, name: string, tokens: int}>
     */
    private function dataEntries(array $filters, ?array $ids, string $query, int $limit): Collection
    {
        $source = $filters['data_source'] ?? null;
        $missing = \is_string($filters['missing_dimension'] ?? null) ? $filters['missing_dimension'] : null;

        $sourceId = \is_string($source)
            ? DataSource::query()->where('id', $source)->orWhere('slug', $source)->value('id')
            : null;

        if ($ids === null && $sourceId === null) {
            throw new ToolError('Pass data_source (ID or slug) or ids to find data entries.');
        }

        return DataEntry::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($sourceId !== null, fn ($q) => $q->where('data_source_id', $sourceId))
            ->when($query !== '', fn ($q) => $q->where('key', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('key')->orderBy('id')
            ->limit($missing !== null ? self::MAX_ENTRIES * 4 : $limit)
            ->get()
            ->filter(fn (DataEntry $entry) => $missing === null || blank($entry->dimensions[$missing] ?? null))
            ->map(fn (DataEntry $entry) => [
                'id' => $entry->id,
                'name' => (string) $entry->key,
                'tokens' => $this->tokens([$entry->value, $entry->dimensions]),
            ]);
    }

    /**
     * @return Collection<int, array{id: string, name: string, tokens: int}>
     */
    private function assets(array $filters, ?array $ids, string $query, int $limit): Collection
    {
        $missing = \is_string($filters['missing_field'] ?? null) ? $filters['missing_field'] : null;

        return Asset::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when(\is_string($filters['folder_id'] ?? null), fn ($q) => $q->where('folder_id', $filters['folder_id']))
            ->when($query !== '', fn ($q) => $q->where('filename', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('filename')->orderBy('id')
            ->limit($missing !== null ? self::MAX_ENTRIES * 4 : $limit)
            ->get()
            ->filter(fn (Asset $asset) => $missing === null || blank(data_get($asset->data, "fields.{$missing}")))
            ->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'name' => (string) $asset->filename,
                'tokens' => $this->tokens(data_get($asset->data, 'fields', [])),
            ]);
    }

    private function tokens(mixed $value): int
    {
        return (int) ceil(\strlen(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '') / self::CHARS_PER_TOKEN);
    }
}
