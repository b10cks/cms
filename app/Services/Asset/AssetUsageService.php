<?php

namespace App\Services\Asset;

use App\Models\Space\Asset;
use App\Models\Space\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AssetUsageService
{
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

        foreach ($this->getContentsWithActiveVersions() as $content) {
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
        $contentIds = $this->getContentsWithActiveVersions()
            ->filter(
                fn(object $content) =>
                \in_array($asset->id, $this->normalizeAssetIds($content->current_asset_ids), true)
                || \in_array($asset->id, $this->normalizeAssetIds($content->published_asset_ids), true)
            )
            ->pluck('id');

        return Content::query()
            ->whereKey($contentIds->all())
            ->with([
                'block:id,name,icon,color,slug',
                'current_version:id,content_id,asset_ids',
                'published_version:id,content_id,asset_ids',
            ]);
    }

    private function getContentsWithActiveVersions(): Collection
    {
        return Content::query()
            ->leftJoin('content_versions as current_version', 'contents.current_version_id', '=', 'current_version.id')
            ->leftJoin('content_versions as published_version', 'contents.published_version_id', '=', 'published_version.id')
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
