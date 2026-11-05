<?php

namespace App\Services\Ai\Assets;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Asset;
use Illuminate\Support\Collection;

/**
 * Asset proposals grouped for review by their `group` label: what the group
 * changes, a few representative assets, and which items "accept group" may
 * take. Every item stays listed, so each change can still be inspected and
 * decided on its own. New folders and tags form a group of their own that is
 * reviewed first.
 */
final class AssetReviewGroups
{
    public const STRUCTURE_GROUP = 'structure';

    private const EXAMPLES = 4;

    /**
     * @param  Collection<int, AiChangesetItem>  $items  asset-domain items of a changeset
     * @return list<array{key: string, label: string|null, assets: int, examples: list<string>, item_ids: list<string>, open_item_ids: list<string>, bulk_item_ids: list<string>, flagged: int, conflicts: int, kinds: array<string, int>}>
     */
    public static function of(Collection $items): array
    {
        $groups = $items
            ->filter(fn (AiChangesetItem $item) => AssetReview::handles($item->target_type))
            ->groupBy(fn (AiChangesetItem $item) => \in_array($item->target_type, AssetReview::STRUCTURE_TYPES, true)
                ? self::STRUCTURE_GROUP
                : 'group:'.($item->review['group'] ?? ''));

        return $groups
            ->sortKeys()
            // New folders and tags are approved first.
            ->sortBy(fn (Collection $group, string $key) => $key === self::STRUCTURE_GROUP ? 0 : 1)
            ->map(fn (Collection $group, string $key) => self::summary($key, $group))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AiChangesetItem>  $items
     * @return array{key: string, label: string|null, assets: int, examples: list<string>, item_ids: list<string>, open_item_ids: list<string>, bulk_item_ids: list<string>, flagged: int, conflicts: int, kinds: array<string, int>}
     */
    private static function summary(string $key, Collection $items): array
    {
        $open = $items->filter(fn (AiChangesetItem $item) => $item->isOpen());
        $assets = $items->where('target_type', AiChangesetItem::TARGET_ASSET)->pluck('target_id')->unique()->values();

        return [
            'key' => $key,
            'label' => $key === self::STRUCTURE_GROUP ? null : ($items->first()->review['group'] ?? null),
            'assets' => $assets->count(),
            'examples' => self::examples($items),
            'item_ids' => $items->pluck('id')->values()->all(),
            'open_item_ids' => $open->pluck('id')->values()->all(),
            'bulk_item_ids' => $open->reject(fn (AiChangesetItem $item) => AssetReview::excludedFromBulk($item))->pluck('id')->values()->all(),
            'flagged' => $open->filter(fn (AiChangesetItem $item) => ($item->review['flags'] ?? []) !== [])->count(),
            'conflicts' => $open->filter(fn (AiChangesetItem $item) => $item->status === AiChangesetItemStatus::Conflict)->count(),
            'kinds' => $items->countBy(fn (AiChangesetItem $item) => match (true) {
                $item->target_type !== AiChangesetItem::TARGET_ASSET => $item->target_type,
                str_starts_with((string) $item->path, 'fields.') => 'fields',
                default => (string) $item->path,
            })->all(),
        ];
    }

    /**
     * Up to four assets that show the group: one per source folder in turn,
     * so the sample is not four near-identical files from one shoot; for new
     * folders and tags, the assets that justify them.
     *
     * @param  Collection<int, AiChangesetItem>  $items
     * @return list<string>
     */
    private static function examples(Collection $items): array
    {
        $structure = $items->whereIn('target_type', AssetReview::STRUCTURE_TYPES);
        if ($structure->isNotEmpty()) {
            return $structure->flatMap(fn (AiChangesetItem $item) => $item->review['examples'] ?? [])->unique()->take(self::EXAMPLES)->values()->all();
        }

        $ids = $items->where('target_type', AiChangesetItem::TARGET_ASSET)->pluck('target_id')->unique()->sort()->values();
        $byFolder = Asset::query()->whereIn('id', $ids->take(500))->orderBy('id')->get(['id', 'folder_id'])
            ->groupBy(fn (Asset $asset) => (string) $asset->folder_id)
            ->map(fn (Collection $assets) => $assets->pluck('id')->values())
            ->values();

        $picked = [];
        for ($round = 0; \count($picked) < self::EXAMPLES && $byFolder->contains(fn (Collection $folder) => isset($folder[$round])); $round++) {
            foreach ($byFolder as $folder) {
                if (isset($folder[$round]) && \count($picked) < self::EXAMPLES) {
                    $picked[] = $folder[$round];
                }
            }
        }

        return $picked;
    }
}
