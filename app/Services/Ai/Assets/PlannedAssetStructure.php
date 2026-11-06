<?php

namespace App\Services\Ai\Assets;

use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use Illuminate\Support\Str;

/**
 * The folders and tags an asset operation's approved plan introduces, as
 * structure proposals in the operation's changeset. They exist once, before
 * the first batch, so every batch refers to them as `new:<ID>` and the
 * applier creates each with the first accepted change that uses it, or not
 * at all when the editor rejects it. Batches cannot propose other structure.
 */
class PlannedAssetStructure
{
    public function __construct(private readonly AssetStructure $structure) {}

    /**
     * Create the missing proposals; safe to call again.
     *
     * @return list<string> IDs of the planned structure proposals
     */
    public function materialize(AiOperation $operation): array
    {
        $plan = (array) ($operation->approved_plan['structure'] ?? []);
        $folders = array_values((array) ($plan['folders'] ?? []));
        $tags = array_values((array) ($plan['tags'] ?? []));
        if ($folders === [] && $tags === []) {
            return [];
        }

        $changeset = AiChangeset::query()->firstOrCreate(['operation_id' => $operation->id], [
            'space_id' => $operation->space_id,
            'actor_id' => $operation->actor_id,
            'title' => Str::limit(trim((string) strtok($operation->prompt, "\n")), 180, ''),
            'inline' => false,
        ]);
        $existing = $changeset->items()
            ->whereIn('target_type', AssetReview::STRUCTURE_TYPES)
            ->get()
            ->filter(fn (AiChangesetItem $item) => \is_string($item->review['planned'] ?? null))
            ->keyBy(fn (AiChangesetItem $item) => $item->review['planned']);
        $ids = [];

        foreach ($tags as $tag) {
            $key = self::tagKey((string) $tag['name']);
            $ids[] = ($existing[$key] ?? $changeset->items()->create([
                'operation' => AiChangesetItem::OPERATION_CREATE,
                'target_type' => AiChangesetItem::TARGET_ASSET_TAG,
                'target_label' => $tag['name'],
                'after' => ['name' => $tag['name']],
                'review' => ['planned' => $key, 'reason' => $tag['reason'], 'examples' => $tag['examples']],
            ]))->id;
        }

        // Parents first: a planned folder may sit in another planned folder.
        $made = [];
        while ($folders !== []) {
            $left = [];
            foreach ($folders as $folder) {
                $parentKey = $folder['parent_key'] ?? null;
                if ($parentKey !== null && ! isset($made[$parentKey])) {
                    $left[] = $folder;

                    continue;
                }

                $parent = $parentKey !== null ? $made[$parentKey] : null;
                $parentRef = $parent !== null ? AssetReview::newRef($parent->id) : $folder['parent_id'];
                $parentLabel = $parent !== null ? (string) $parent->target_label : $this->structure->path($folder['parent_id']);
                $key = self::folderKey((string) $folder['key']);

                $made[$folder['key']] = $existing[$key] ?? $changeset->items()->create([
                    'operation' => AiChangesetItem::OPERATION_CREATE,
                    'target_type' => AiChangesetItem::TARGET_ASSET_FOLDER,
                    'target_label' => ($parentLabel !== '' ? "{$parentLabel} / " : '').$folder['name'],
                    'after' => ['name' => $folder['name'], 'parent' => $parentRef],
                    'review' => array_filter([
                        'planned' => $key,
                        'reason' => $folder['reason'],
                        'examples' => $folder['examples'],
                        'depends_on' => $parent !== null ? [$parent->id] : null,
                        'labels' => [$parentRef ?? 'root' => $parentLabel],
                    ], fn ($value) => $value !== null),
                ]);
                $ids[] = $made[$folder['key']]->id;
            }

            // A validated plan has no cycles; never loop on one that has.
            if (\count($left) === \count($folders)) {
                break;
            }
            $folders = $left;
        }

        $changeset->refreshCounts();

        return $ids;
    }

    public static function tagKey(string $name): string
    {
        return 'tag:'.AssetReview::foldName($name);
    }

    public static function folderKey(string $planKey): string
    {
        return "folder:{$planKey}";
    }
}
