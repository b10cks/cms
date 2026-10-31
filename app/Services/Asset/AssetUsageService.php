<?php

namespace App\Services\Asset;

use App\Models\Space\Asset;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\Release;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Where assets are used, derived from `content_versions.asset_ids`.
 *
 * An entry uses an asset when a version that can still go live references it:
 * the current (draft) version, the published version, a version scheduled to
 * publish, or a version in a release that has not been published yet. Entries
 * in the trash are counted apart, as restoring them brings the reference back.
 */
class AssetUsageService
{
    /**
     * Set `linked_contents_count` and `trashed_contents_count` on each asset,
     * for `AssetResource`.
     *
     * @param  iterable<Asset>  $assets
     */
    public function attachCounts(iterable $assets): void
    {
        $assets = collect($assets);
        $counts = $this->counts($assets->pluck('id')->unique()->values()->all());

        foreach ($assets as $asset) {
            $asset->setAttribute('linked_contents_count', $counts[$asset->id]['linked']);
            $asset->setAttribute('trashed_contents_count', $counts[$asset->id]['trashed']);
        }
    }

    /**
     * @param  list<string>  $assetIds
     * @return array<string, array{linked: int, trashed: int}>
     */
    public function counts(array $assetIds): array
    {
        $contents = array_fill_keys($assetIds, ['linked' => [], 'trashed' => []]);

        foreach ($this->references($assetIds) as $reference) {
            $contents[$reference['asset']][$reference['trashed'] ? 'trashed' : 'linked'][$reference['content']] = true;
        }

        return array_map(
            static fn (array $usage): array => [
                'linked' => \count($usage['linked']),
                'trashed' => \count($usage['trashed']),
            ],
            $contents,
        );
    }

    /**
     * How each entry outside the trash uses the asset, keyed by content id.
     *
     * @return array<string, array{current: bool, published: bool, pending: bool}>
     */
    public function linkedContents(Asset $asset): array
    {
        $usage = [];

        foreach ($this->references([$asset->id]) as $reference) {
            if ($reference['trashed']) {
                continue;
            }

            $flags = $usage[$reference['content']] ?? ['current' => false, 'published' => false, 'pending' => false];

            $usage[$reference['content']] = [
                'current' => $flags['current'] || $reference['current'],
                'published' => $flags['published'] || $reference['published'],
                'pending' => $flags['pending'] || $reference['pending'],
            ];
        }

        return $usage;
    }

    /**
     * Whether a live entry's published version shows the asset, i.e. whether
     * the delivery API serves its data right now.
     */
    public function isPublished(Asset $asset): bool
    {
        return ContentVersion::query()
            ->referencingAssets([$asset->id])
            ->whereExists(fn (QueryBuilder $query) => $query
                ->from('contents')
                ->whereColumn('contents.published_version_id', 'content_versions.id')
                ->whereNotNull('contents.published_at')
                ->whereNull('contents.deleted_at'))
            ->exists();
    }

    /**
     * One row per referenced asset on each version that can still go live.
     *
     * The version lookup runs on the multi-valued `asset_ids` index, and the
     * pointer checks on the `contents` version-pointer indexes, so neither
     * scans a table.
     *
     * @param  list<string>  $assetIds
     * @return list<array{asset: string, content: string, trashed: bool, current: bool, published: bool, pending: bool}>
     */
    private function references(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $versions = ContentVersion::query()
            ->referencingAssets($assetIds)
            ->where(fn (Builder $query) => $query
                ->whereExists(fn (QueryBuilder $query) => $query
                    ->from('contents')
                    ->whereColumn('contents.current_version_id', 'content_versions.id'))
                ->orWhereExists(fn (QueryBuilder $query) => $query
                    ->from('contents')
                    ->whereColumn('contents.published_version_id', 'content_versions.id'))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('published_at')
                    ->where(fn (Builder $query) => $query
                        ->whereNotNull('scheduled_at')
                        ->orWhereIn('release_id', Release::query()->whereNull('published_at')->select('id')))))
            ->toBase()
            ->get(['id', 'content_id', 'asset_ids', 'published_at', 'scheduled_at', 'release_id']);

        if ($versions->isEmpty()) {
            return [];
        }

        $contents = Content::withTrashed()
            ->whereKey($versions->pluck('content_id')->unique()->values()->all())
            ->toBase()
            ->get(['id', 'current_version_id', 'published_version_id', 'deleted_at'])
            ->keyBy('id');

        $releaseIds = $versions->pluck('release_id')->filter()->unique()->values()->all();
        $openReleaseIds = $releaseIds === []
            ? []
            : Release::query()->whereKey($releaseIds)->whereNull('published_at')->pluck('id')->flip()->all();

        $requested = array_flip($assetIds);
        $references = [];

        foreach ($versions as $version) {
            $content = $contents->get($version->content_id);

            if (! $content) {
                continue;
            }

            $pending = $version->published_at === null
                && ($version->scheduled_at !== null || isset($openReleaseIds[$version->release_id ?? '']));

            foreach (json_decode($version->asset_ids ?? '[]', true) ?: [] as $assetId) {
                if (! \is_string($assetId) || ! isset($requested[$assetId])) {
                    continue;
                }

                $references[] = [
                    'asset' => $assetId,
                    'content' => $content->id,
                    'trashed' => $content->deleted_at !== null,
                    'current' => $content->current_version_id === $version->id,
                    'published' => $content->published_version_id === $version->id,
                    'pending' => $pending,
                ];
            }
        }

        return $references;
    }
}
