<?php

namespace App\Services\Ai\Tools;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\AssetFolder;
use App\Models\Space\AssetTag;
use App\Models\User;
use App\Services\Ai\Assets\AssetFieldRules;
use App\Services\Ai\Assets\AssetPlan;
use App\Services\Ai\Assets\AssetReview;
use App\Services\Ai\Assets\AssetStructure;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Knowledge\GlossaryBrief;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Run\ToolError;
use App\Services\Asset\AssetMetadataFieldResolver;
use App\Services\Auth\AuthorizationService;

/**
 * The conventions of the asset library: folders with their field rules, the
 * tag taxonomy by use, the metadata languages, and what the run's approved
 * plan lets it change. New folders and tags extend these conventions. Folders
 * and tags come in bounded pages: `folders_page` and `tags_page` say when an
 * answer is only part of the library, and a query or offset reaches the rest.
 */
class GetAssetLibraryTool implements AiToolInterface
{
    public const NAME = 'get_asset_library';

    private const MAX_FOLDERS = 500;

    private const MAX_TAGS = 300;

    public function __construct(
        private readonly AiRun $run,
        private readonly Space $space,
        private readonly User $actor,
        private readonly AuthorizationService $auth,
        private readonly AssetFieldRules $rules,
        private readonly AssetStructure $structure,
        private readonly KnowledgeResolver $knowledge,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Returns the asset library conventions: metadata languages (`_default` is the default language), `glossary` with wording never to write per language, folders with their paths, asset counts, '
            .'AI-writable and required fields, existing tags by use, proposed new folders and tags with their new:<ID> refs, '
            .'and the plan of this run (what it may overwrite, remove or move). '
            .'Pass folder_id to list only that folder and its subfolders. Call it before proposing tags, folders or moves. '
            .'Large libraries come in pages: when `tags_page` or `folders_page` says `truncated`, the list is not the whole library. '
            .'Search with tag_query or folder_query, or continue from `next_offset` with tag_offset or folder_offset, before you conclude a tag or folder does not exist.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'folder_id' => ['type' => 'string', 'description' => 'Only this folder and its subfolders.'],
                'folder_query' => ['type' => 'string', 'description' => 'Only folders whose path contains this text.'],
                'folder_offset' => ['type' => 'integer', 'minimum' => 0],
                'tag_query' => ['type' => 'string', 'description' => 'Only tags whose name contains this text.'],
                'tag_offset' => ['type' => 'integer', 'minimum' => 0],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        if (! $this->can('assets.view')) {
            throw new ToolError('You do not have access to the asset library.');
        }

        $root = \is_string($input['folder_id'] ?? null) && $input['folder_id'] !== '' ? $input['folder_id'] : null;
        if ($root !== null && ! $this->structure->folder($root)) {
            throw new ToolError('No asset folder with this ID.');
        }

        $plan = AssetPlan::fromSetup($this->run->setup ?? []);
        [$folders, $foldersPage] = $this->can('asset_folders.view')
            ? $this->folders($root, self::query($input['folder_query'] ?? null), self::offset($input['folder_offset'] ?? null))
            : [[], self::page(0, 0, 0)];
        [$tags, $tagsPage] = $this->can('asset_tags.view')
            ? $this->tags(self::query($input['tag_query'] ?? null), self::offset($input['tag_offset'] ?? null))
            : [[], self::page(0, 0, 0)];

        return [
            'languages' => $languages = $this->languages(),
            ...$this->glossary($languages),
            'fields' => $this->fields(null),
            'folders' => $folders,
            'folders_page' => $foldersPage,
            'tags' => $tags,
            'tags_page' => $tagsPage,
            ...$this->proposed($plan),
            'plan' => $plan->toArray(),
        ];
    }

    /** @return array<string, string> language key => label */
    private function languages(): array
    {
        $names = ['_default' => (string) $this->space->settings->getDefaultLanguage().' (default)'];
        foreach ($this->space->settings->languages ?? [] as $language) {
            $names[(string) ($language['code'] ?? '')] = (string) ($language['name'] ?? $language['code'] ?? '');
        }

        return array_intersect_key($names, array_flip($this->rules->languages($this->space)));
    }

    /**
     * Wording never to write, per language key of the library.
     *
     * @param  array<string, string>  $languages
     * @return array{glossary?: array<string, string>}
     */
    private function glossary(array $languages): array
    {
        $concepts = $this->knowledge->policy($this->space, $this->run)->concepts;
        $bans = [];
        foreach (array_keys($languages) as $key) {
            $bans[$key] = GlossaryBrief::bans($concepts, [], $key === AssetMetadataFieldResolver::DEFAULT_LANGUAGE_KEY ? $this->space->settings->getDefaultLanguage() : $key);
        }
        $bans = array_filter($bans);

        return $bans === [] ? [] : ['glossary' => $bans];
    }

    /** @return list<array{key: string, label: string, required: bool, ai: bool}> */
    private function fields(AssetFolder|string|null $folder): array
    {
        $writable = $this->rules->keys($this->space, $folder);

        return array_map(fn (array $field) => [
            'key' => $field['key'],
            'label' => $field['label'],
            'required' => $field['required'],
            'ai' => \in_array($field['key'], $writable, true),
        ], $this->rules->definitions($this->space, $folder));
    }

    /** @return array{0: list<array<string, mixed>>, 1: array{total: int, offset: int, returned: int, next_offset: int|null, truncated: bool}} */
    private function folders(?string $root, ?string $query, int $offset): array
    {
        $paths = [];
        foreach ($root !== null ? $this->structure->descendantIds($root) : array_keys($this->structure->folders()) as $id) {
            $path = $this->structure->path($id);
            if ($query === null || mb_stripos($path, $query) !== false) {
                $paths[$id] = $path;
            }
        }
        asort($paths, SORT_STRING);

        $page = \array_slice($paths, $offset, self::MAX_FOLDERS, preserve_keys: true);
        $counts = AssetFolder::query()->whereIn('id', array_keys($page))->withCount('assets')->pluck('assets_count', 'id');

        return [array_map(fn (string $id, string $path) => [
            'id' => $id,
            'path' => $path,
            'parent_id' => $this->structure->folder($id)?->parent_id,
            'assets' => (int) ($counts[$id] ?? 0),
            'fields' => $this->fields($this->structure->folder($id)),
        ], array_keys($page), $page), self::page(\count($paths), $offset, \count($page))];
    }

    /** @return array{0: list<array{id: string, name: string, assets: int}>, 1: array{total: int, offset: int, returned: int, next_offset: int|null, truncated: bool}} */
    private function tags(?string $query, int $offset): array
    {
        $matching = AssetTag::query()->when($query !== null, fn ($tags) => $tags->where(fn ($names) => AssetStructure::orWhereTagNameContains($names, (string) $query)));
        $total = (clone $matching)->count();
        $tags = $matching
            ->withCount('assets')
            ->orderByDesc('assets_count')
            ->orderBy('name')
            ->orderBy('id')
            ->offset($offset)
            ->limit(self::MAX_TAGS)
            ->get()
            ->map(fn (AssetTag $tag) => ['id' => $tag->id, 'name' => (string) $tag->name, 'assets' => (int) $tag->assets_count])
            ->all();

        return [$tags, self::page($total, $offset, \count($tags))];
    }

    /** @return array{total: int, offset: int, returned: int, next_offset: int|null, truncated: bool} */
    private static function page(int $total, int $offset, int $returned): array
    {
        $next = $offset + $returned;

        return [
            'total' => $total,
            'offset' => $offset,
            'returned' => $returned,
            'next_offset' => $returned > 0 && $next < $total ? $next : null,
            'truncated' => $returned < $total,
        ];
    }

    private static function query(mixed $query): ?string
    {
        return \is_string($query) && trim($query) !== '' ? trim($query) : null;
    }

    private static function offset(mixed $offset): int
    {
        return \is_int($offset) ? max(0, $offset) : 0;
    }

    /**
     * New folders and tags proposed in this run or approved with the plan
     * and not yet created, with the refs asset proposals use for them.
     *
     * @return array{proposed: list<array{ref: string, type: string, name: string, status: string}>, proposed_truncated: bool}
     */
    private function proposed(AssetPlan $plan): array
    {
        $items = AiChangesetItem::query()
            ->whereIn('target_type', AssetReview::STRUCTURE_TYPES)
            ->where('operation', AiChangesetItem::OPERATION_CREATE)
            ->whereIn('status', [AiChangesetItemStatus::Pending->value, AiChangesetItemStatus::Conflict->value])
            ->where(fn ($query) => $query
                ->whereIn('id', $plan->approvedStructure)
                ->orWhereHas('changeset', fn ($changeset) => $changeset->ofRun($this->run)))
            ->orderBy('id')
            ->limit(self::MAX_TAGS + 1)
            ->get();

        return [
            'proposed' => $items->take(self::MAX_TAGS)->map(fn (AiChangesetItem $item) => [
                'ref' => AssetReview::newRef($item->id),
                'type' => $item->target_type === AiChangesetItem::TARGET_ASSET_TAG ? 'tag' : 'folder',
                'name' => (string) $item->target_label,
                'status' => \in_array($item->id, $plan->approvedStructure, true) ? 'approved' : 'proposed',
            ])->all(),
            'proposed_truncated' => $items->count() > self::MAX_TAGS,
        ];
    }

    private function can(string $ability): bool
    {
        return $this->auth->canInSpace($this->actor, $this->space, $ability);
    }
}
