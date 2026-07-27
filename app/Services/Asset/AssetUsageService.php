<?php

namespace App\Services\Asset;

use App\Models\Space\Asset;
use App\Models\Space\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AssetUsageService
{
    /**
     * Request-level memo of linked-content rows keyed by the asset id set, so
     * a listing page and its usage counts don't query twice.
     *
     * @var array<string, Collection>
     */
    private array $linkedContentRows = [];

    public function getUsageCountsForAssets(Collection $assets): array
    {
        $assetIds = $assets
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($assetIds === []) {
            return [];
        }

        $counts = array_fill_keys($assetIds, 0);

        foreach ($this->getContentsLinkedToAssets($assetIds) as $content) {
            $linkedAssetIds = collect([
                ...$this->normalizeAssetIds($content->current_asset_ids),
                ...$this->normalizeAssetIds($content->published_asset_ids),
            ])
                ->filter(fn(mixed $assetId): bool => is_string($assetId) && isset($counts[$assetId]))
                ->unique();

            foreach ($linkedAssetIds as $assetId) {
                $counts[$assetId]++;
            }
        }

        return $counts;
    }

    public function getUsageCountForAsset(Asset $asset): int
    {
        return $this->getUsageCountsForAssets(collect([$asset]))[$asset->id] ?? 0;
    }

    public function getLinkedContentsQuery(Asset $asset): Builder
    {
        $contentIds = $this->getContentsLinkedToAssets([$asset->id])->pluck('id');

        return Content::query()
            ->whereKey($contentIds->all())
            ->with([
                'block:id,name,icon,color,slug',
                'current_version:id,content_id,asset_ids',
                'published_version:id,content_id,asset_ids',
            ]);
    }

    /**
     * Contents whose current or published version references one of the given
     * assets. Filtering happens in the database via JSON containment, so only
     * the (small) matching row set is hydrated instead of the whole table.
     */
    private function getContentsLinkedToAssets(array $assetIds): Collection
    {
        $assetIds = array_values(array_unique($assetIds));
        sort($assetIds);
        $cacheKey = implode(',', $assetIds);

        return $this->linkedContentRows[$cacheKey] ??= Content::query()
            ->leftJoin('content_versions as current_version', 'contents.current_version_id', '=', 'current_version.id')
            ->leftJoin('content_versions as published_version', 'contents.published_version_id', '=', 'published_version.id')
            ->where(function (Builder $query) use ($assetIds): void {
                foreach ($assetIds as $assetId) {
                    $query
                        ->orWhereJsonContains('current_version.asset_ids', $assetId)
                        ->orWhereJsonContains('published_version.asset_ids', $assetId);
                }
            })
            ->get([
                'contents.id',
                'current_version.asset_ids as current_asset_ids',
                'published_version.asset_ids as published_asset_ids',
            ]);
    }

    private function normalizeAssetIds(mixed $assetIds): array
    {
        if (\is_array($assetIds)) {
            return $assetIds;
        }

        if (\is_string($assetIds) && $assetIds !== '') {
            $decoded = json_decode($assetIds, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
