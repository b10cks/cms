<?php

namespace App\Services\Asset;

use App\Events\Space\AssetCollectionContentChanged;
use App\Jobs\Space\BuildAssetPackageJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\AssetPackage;
use App\Models\Space\AssetShare;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Creates asset download packages and resolves the asset set behind a
 * package/share source ('selection' | 'folder' | 'collection').
 *
 * Asset queries run on the current space's database connection; callers must
 * ensure the space context is bound (request `space` param or `currentSpace`).
 */
class AssetPackageService
{
    /**
     * Create a package row from validated data and dispatch its build job.
     *
     * @param  array{name?: ?string, source_type: string, collection_id?: ?string, folder_id?: ?string, asset_ids?: ?array}  $data
     */
    public function createPackage(Space $space, array $data, ?User $creator = null): AssetPackage
    {
        $package = AssetPackage::create([
            'name' => $data['name'] ?? null,
            'source_type' => $data['source_type'],
            'collection_id' => $data['collection_id'] ?? null,
            'folder_id' => $data['folder_id'] ?? null,
            'asset_ids' => $data['asset_ids'] ?? null,
            'state' => AssetPackage::STATE_PENDING,
            'created_by_id' => $creator?->id,
            // Set up-front so failed/abandoned rows are pruned too; a
            // successful build refreshes it from its completion time.
            'expires_at' => now()->addDays((int) config('asset_distribution.package_expiry_days', 7)),
        ]);

        BuildAssetPackageJob::dispatch($package, $space);

        return $package;
    }

    /**
     * Ensure a share has a fresh (completed, non-stale, non-expired) package,
     * rebuilding when needed. Returns the package the share should use.
     *
     * Rebuilds are serialized per share (concurrent public download polls must
     * not each dispatch a build) and failed builds back off for a cooldown
     * instead of being re-dispatched on every request.
     */
    public function ensureFreshPackageForShare(Space $space, AssetShare $share): AssetPackage
    {
        if ($package = $this->reusablePackageFor($share)) {
            return $package;
        }

        $lock = Cache::lock("asset-package:ensure:{$space->id}:{$share->id}", 30);

        try {
            return $lock->block(10, function () use ($space, $share) {
                $share->refresh();

                if ($package = $this->reusablePackageFor($share)) {
                    return $package;
                }

                $package = $this->createPackage($space, [
                    'name' => $share->name,
                    'source_type' => $share->source_type,
                    'collection_id' => $share->collection_id,
                    'folder_id' => $share->folder_id,
                    'asset_ids' => $share->asset_ids,
                ]);

                $share->forceFill(['package_id' => $package->id])->save();

                return $package;
            });
        } catch (LockTimeoutException) {
            // Another request holds the build slot; serve whatever the share
            // points at right now rather than duplicating the build.
            $share->refresh();

            return $share->package ?? abort(503, 'The package build is being prepared, please retry shortly.');
        }
    }

    /**
     * The share's current package if it can be served or is already being
     * (re)built — including a recently failed build still in its cooldown.
     */
    private function reusablePackageFor(AssetShare $share): ?AssetPackage
    {
        $package = $share->package;

        if (! $package) {
            return null;
        }

        if ($package->isDownloadable() || $package->isPending() || $package->isBuilding()) {
            return $package;
        }

        $cooldownMinutes = (int) config('asset_distribution.failed_build_cooldown_minutes', 10);

        if ($package->isFailed() && $package->updated_at?->gt(now()->subMinutes($cooldownMinutes))) {
            return $package;
        }

        return null;
    }

    /**
     * Mark all packages sourced from a collection as stale so the next
     * download triggers a rebuild. Intended to be called whenever collection
     * membership (items or smart rules) changes.
     */
    public static function markStaleForCollection(string $collectionId): void
    {
        AssetPackage::query()
            ->where('source_type', AssetPackage::SOURCE_COLLECTION)
            ->where('collection_id', $collectionId)
            ->update(['is_stale' => true]);

        // Every caller reaches this exactly when the collection's content
        // changed, so the live-update broadcast rides the same choke point.
        $space = request('space') ?? SpaceContext::current();
        if ($space) {
            broadcast(new AssetCollectionContentChanged($space->id, $collectionId))->toOthers();
        }
    }

    /**
     * Resolve the asset query for a package's or share's source definition.
     *
     * @return Builder<Asset>
     */
    public function resolveAssetQueryFor(AssetPackage|AssetShare $source): Builder
    {
        return $this->resolveAssetQuery(
            $source->source_type,
            $source->collection_id,
            $source->folder_id,
            $source->asset_ids,
        );
    }

    /**
     * @param  array<int, string>|null  $assetIds
     * @return Builder<Asset>
     */
    public function resolveAssetQuery(string $sourceType, ?string $collectionId, ?string $folderId, ?array $assetIds): Builder
    {
        return match ($sourceType) {
            'selection' => $this->selectionQuery($assetIds ?? []),
            'folder' => $this->folderQuery($folderId),
            'collection' => $this->collectionQuery($collectionId),
            default => throw new \InvalidArgumentException("Unknown package source type: {$sourceType}"),
        };
    }

    /**
     * @param  array<int, string>  $assetIds
     * @return Builder<Asset>
     */
    private function selectionQuery(array $assetIds): Builder
    {
        if (empty($assetIds)) {
            throw new \RuntimeException('The selection contains no assets.');
        }

        return Asset::query()->whereIn('id', $assetIds);
    }

    /**
     * Assets in the folder including all subfolders.
     *
     * @return Builder<Asset>
     */
    private function folderQuery(?string $folderId): Builder
    {
        if (! $folderId) {
            throw new \RuntimeException('No folder specified for the folder source.');
        }

        $folderIds = [$folderId];
        $frontier = [$folderId];

        while (! empty($frontier)) {
            $frontier = AssetFolder::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $folderIds = array_merge($folderIds, $frontier);
        }

        return Asset::query()->whereIn('folder_id', array_unique($folderIds));
    }

    /**
     * Assets in a manual or smart collection. Guarded defensively: collections
     * are an optional feature built in parallel — resolve fails loudly (and
     * the package build records the error) when unavailable or invalid.
     *
     * @return Builder<Asset>
     */
    private function collectionQuery(?string $collectionId): Builder
    {
        if (! $collectionId) {
            throw new \RuntimeException('No collection specified for the collection source.');
        }

        $collectionClass = '\App\Models\Space\AssetCollection';

        if (! class_exists($collectionClass)) {
            throw new \RuntimeException('Asset collections are not available.');
        }

        $collection = $collectionClass::query()->find($collectionId);

        if (! $collection) {
            throw new \RuntimeException("Asset collection not found: {$collectionId}");
        }

        if (($collection->type ?? 'manual') === 'smart') {
            $rules = $collection->rules;

            if (! is_array($rules) || empty($rules)) {
                throw new \RuntimeException('Smart collection has no valid rules.');
            }

            $ruleServiceClass = '\App\Services\Asset\SmartCollectionRuleService';

            if (! class_exists($ruleServiceClass)) {
                throw new \RuntimeException('Smart collection rules are not available.');
            }

            $query = Asset::query();
            app($ruleServiceClass)->apply($query, $rules);

            return $query;
        }

        return Asset::query()->whereIn('id', function ($query) use ($collectionId) {
            $query->select('asset_id')
                ->from('asset_collection_items')
                ->where('collection_id', $collectionId);
        });
    }
}
