<?php

namespace App\Services\Ai\Changesets;

use App\Models\Management\AiChangesetItem;
use App\Models\Space\Asset;
use App\Models\Space\Content;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Ai\Run\ToolError;
use Illuminate\Support\Collection;

/**
 * Resolves a scope (explicit IDs, folder, block, locale gaps) to entries.
 * `find_entries` and the preflight use the same resolution, so a confirmed
 * estimate covers exactly the entries the run then works on.
 */
class EntryScope
{
    public const MAX_ENTRIES = 500;

    /** Rough characters per token for estimates. */
    private const CHARS_PER_TOKEN = 4;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: string, name: string, tokens: int}>
     */
    public function resolve(string $type, array $filters, int $limit = self::MAX_ENTRIES): array
    {
        $limit = max(1, min(self::MAX_ENTRIES, $limit));
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
