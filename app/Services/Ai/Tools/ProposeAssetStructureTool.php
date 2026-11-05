<?php

namespace App\Services\Ai\Tools;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Asset;
use App\Services\Ai\Assets\AssetReview;
use App\Services\Ai\Assets\InspectionImages;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use Illuminate\Support\Str;

/**
 * Proposes new asset folders and tags, each justified by a reason and the
 * assets it groups. Nothing is created: an editor approves each one on its
 * own, and asset proposals refer to it as `new:<ID>` until it is accepted.
 * Names that already exist are refused with the existing ID, so the library
 * keeps one folder or tag per name. Inside an operation the editor approved
 * new structure with the plan; the tool only hands out those refs.
 */
class ProposeAssetStructureTool extends AssetProposalTool
{
    public const NAME = 'propose_asset_structure';

    public const MIN_EXAMPLES = 2;

    private const MAX_EXAMPLES = 10;

    private const MAX_ENTRIES = 20;

    private const NAME_MAX_LENGTH = 100;

    private const MAX_TEXT = 300;

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Proposes new asset folders and tags when the existing ones (get_asset_library) do not fit a group of assets you inspected. '
            .'Each needs a `reason` and between '.self::MIN_EXAMPLES.' and '.self::MAX_EXAMPLES.' `example_asset_ids` whose images reached you in an earlier turn. '
            .'A folder\'s `parent` is a folder ID, new:<ID> of a proposed folder, or "root". Nothing is created: an editor approves each one first. '
            .'Returns `ref` values (new:<ID>) to use as add_tags or folder in propose_asset_changes. Existing names are refused with their ID.';
    }

    public function inputSchema(): array
    {
        $examples = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => self::MIN_EXAMPLES, 'maxItems' => self::MAX_EXAMPLES];

        return [
            'type' => 'object',
            'properties' => [
                'folders' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'parent' => ['type' => 'string', 'description' => 'Folder ID, new:<proposal ID> or "root".'],
                            'reason' => ['type' => 'string'],
                            'example_asset_ids' => $examples,
                        ],
                        'required' => ['name', 'parent', 'reason', 'example_asset_ids'],
                    ],
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'example_asset_ids' => $examples,
                        ],
                        'required' => ['name', 'reason', 'example_asset_ids'],
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $folders = array_values(\is_array($input['folders'] ?? null) ? $input['folders'] : []);
        $tags = array_values(\is_array($input['tags'] ?? null) ? $input['tags'] : []);
        if ($folders === [] && $tags === []) {
            throw new ToolError('Pass folders or tags to propose.');
        }
        if (\count($folders) + \count($tags) > self::MAX_ENTRIES) {
            throw new ToolError('Propose at most '.self::MAX_ENTRIES.' folders and tags per call.');
        }

        if (OperationContext::forRun($this->run) !== null) {
            return $this->planned($folders, $tags);
        }
        if (! $this->plan()->newStructure) {
            throw new ToolError('The approved plan does not allow new folders or tags. Use the existing ones.');
        }

        $result = ['folders' => [], 'tags' => [], 'errors' => []];

        if ($folders !== []) {
            $this->target(AiChangesetItem::TARGET_ASSET_FOLDER);
        }
        foreach ($folders as $entry) {
            try {
                $result['folders'][] = $this->folder(\is_array($entry) ? $entry : []);
            } catch (ToolError $e) {
                $result['errors'][] = ['name' => \is_array($entry) ? (string) ($entry['name'] ?? '') : '', 'error' => $e->getMessage()];
            }
        }

        if ($tags !== []) {
            $this->target(AiChangesetItem::TARGET_ASSET_TAG);
        }
        foreach ($tags as $entry) {
            try {
                $result['tags'][] = $this->tag(\is_array($entry) ? $entry : []);
            } catch (ToolError $e) {
                $result['errors'][] = ['name' => \is_array($entry) ? (string) ($entry['name'] ?? '') : '', 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * In an operation batch the approved plan holds all new structure: a
     * planned folder or tag comes back with its ref, anything else is refused.
     *
     * @param  list<mixed>  $folders
     * @param  list<mixed>  $tags
     * @return array{folders: list<array{ref: string, path: string}>, tags: list<array{ref: string, name: string}>, errors: list<array{name: string, error: string}>}
     */
    private function planned(array $folders, array $tags): array
    {
        $planned = AiChangesetItem::query()
            ->whereIn('id', $this->plan()->approvedStructure)
            ->get()
            ->groupBy('target_type');
        $result = ['folders' => [], 'tags' => [], 'errors' => []];

        foreach ([AiChangesetItem::TARGET_ASSET_FOLDER => $folders, AiChangesetItem::TARGET_ASSET_TAG => $tags] as $type => $entries) {
            $available = $planned->get($type, collect());
            foreach ($entries as $entry) {
                $name = \is_array($entry) ? trim((string) ($entry['name'] ?? '')) : '';
                $item = $available->first(fn (AiChangesetItem $item) => AssetReview::foldName((string) ($item->after['name'] ?? '')) === AssetReview::foldName($name));

                if ($item === null) {
                    $result['errors'][] = ['name' => $name, 'error' => 'New folders and tags come only from the approved plan: '
                        .($available->pluck('after.name')->implode(', ') ?: 'it has none').'. Use existing ones, or leave it and say so in your summary.'];
                } elseif ($type === AiChangesetItem::TARGET_ASSET_TAG) {
                    $result['tags'][] = ['ref' => AssetReview::newRef($item->id), 'name' => (string) $item->after['name']];
                } else {
                    $result['folders'][] = ['ref' => AssetReview::newRef($item->id), 'path' => (string) $item->target_label];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{ref: string, path: string}
     */
    private function folder(array $entry): array
    {
        $name = $this->cleanName($entry['name'] ?? null);
        [$reason, $examples] = $this->justification($entry);
        $parent = $this->folderRef($entry['parent'] ?? null);

        if ($parent['depends_on'] === null && $existing = $this->structure->sibling($parent['ref'], $name)) {
            throw new ToolError("The folder \"{$this->structure->path($existing->id)}\" already exists with ID {$existing->id}. Move assets there instead.");
        }

        $path = ($parent['ref'] === null ? '' : "{$parent['label']} / ").$name;
        if ($same = $this->pendingProposal(AiChangesetItem::TARGET_ASSET_FOLDER, $name, $parent['ref'])) {
            return ['ref' => AssetReview::newRef($same->id), 'path' => $path];
        }

        $item = $this->record([[
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_ASSET_FOLDER,
            'target_label' => $path,
            'after' => ['name' => $name, 'parent' => $parent['ref']],
            'review' => array_filter([
                'reason' => $reason,
                'examples' => $examples,
                'depends_on' => $parent['depends_on'] !== null ? [$parent['depends_on']] : null,
                'labels' => [$parent['ref'] ?? 'root' => $parent['label']],
            ], fn ($value) => $value !== null),
        ]])[0];

        return ['ref' => AssetReview::newRef($item->id), 'path' => $path];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{ref: string, name: string}
     */
    private function tag(array $entry): array
    {
        $name = $this->cleanName($entry['name'] ?? null);
        [$reason, $examples] = $this->justification($entry);

        if ($existing = $this->structure->tagNamed($name)) {
            throw new ToolError("The tag \"{$existing->name}\" already exists with ID {$existing->id}. Add it with propose_asset_changes.");
        }

        if ($same = $this->pendingProposal(AiChangesetItem::TARGET_ASSET_TAG, $name)) {
            return ['ref' => AssetReview::newRef($same->id), 'name' => $name];
        }

        $item = $this->record([[
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_ASSET_TAG,
            'target_label' => $name,
            'after' => ['name' => $name],
            'review' => ['reason' => $reason, 'examples' => $examples],
        ]])[0];

        return ['ref' => AssetReview::newRef($item->id), 'name' => $name];
    }

    private function cleanName(mixed $name): string
    {
        $name = trim(strip_tags(\is_string($name) ? $name : ''));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new ToolError('A name needs 1 to '.self::NAME_MAX_LENGTH.' characters.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: list<string>}
     */
    private function justification(array $entry): array
    {
        $reason = Str::limit(trim((string) ($entry['reason'] ?? '')), self::MAX_TEXT, '');
        $examples = array_values(array_unique(array_filter(\is_array($entry['example_asset_ids'] ?? null) ? $entry['example_asset_ids'] : [], 'is_string')));

        if ($reason === '') {
            throw new ToolError('Give the reason the assets justify this.');
        }
        if (\count($examples) < self::MIN_EXAMPLES || \count($examples) > self::MAX_EXAMPLES) {
            throw new ToolError('Name '.self::MIN_EXAMPLES.' to '.self::MAX_EXAMPLES.' example assets it groups.');
        }

        $found = Asset::query()->whereIn('id', $examples)->pluck('id')->all();
        if (\count($found) !== \count($examples)) {
            throw new ToolError('Unknown example assets: '.implode(', ', array_diff($examples, $found)).'.');
        }
        foreach ($examples as $id) {
            $this->ensureInScope(AiChangesetItem::TARGET_ASSET, $id);
        }

        $unseen = array_diff($examples, array_keys(InspectionImages::delivered($this->run->transcript ?? [])));
        if ($unseen !== []) {
            throw new ToolError('Inspect the example assets and wait for their images before proposing structure: '.implode(', ', $unseen).'.');
        }

        return [$reason, $examples];
    }

    /** A pending proposal of this run with the same name (and parent), so a repeated call does not duplicate it. */
    private function pendingProposal(string $type, string $name, ?string $parent = null): ?AiChangesetItem
    {
        $changeset = AiChangeset::query()->ofRun($this->run)->first();

        return $changeset?->items()
            ->where('target_type', $type)
            ->where('status', AiChangesetItemStatus::Pending->value)
            ->get()
            ->first(fn (AiChangesetItem $item) => AssetReview::foldName((string) ($item->after['name'] ?? '')) === AssetReview::foldName($name)
                && ($type === AiChangesetItem::TARGET_ASSET_TAG || ($item->after['parent'] ?? null) === $parent));
    }
}
