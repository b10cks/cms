<?php

namespace App\Http\Controllers\Mgmt;

use App\Exceptions\DuplicateAssetException;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetFilter;
use App\Http\Requests\Asset\ReplaceAssetFileRequest;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use App\Http\Requests\Asset\UploadAssetPosterRequest;
use App\Http\Resources\Management\AssetResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Services\Asset\AssetUsageService;
use App\Services\Auth\AuthorizationService;
use App\Services\Storage\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    /**
     * Display a listing of assets.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.view'), 403);
        $filter = new AssetFilter($request->all());

        $assets = Asset::filter($filter)
            ->with('folder')
            ->paginate($this->perPage($request));

        $this->attachUsageCounts($assets->getCollection(), app(AssetUsageService::class));

        return AssetResource::collection($assets);
    }

    /**
     * Store a newly created asset.
     */
    public function store(
        Space $space,
        StoreAssetRequest $request,
        AssetService $assetService
    ): AssetResource|JsonResponse {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.manage'), 403);
        $validated = $request->validated();

        // If folder_id provided, check it belongs to this space
        $folder = null;
        if (!empty($validated['folder_id'])) {
            $folder = AssetFolder::query()->findOrFail($validated['folder_id']);
        }

        // Store the asset
        try {
            $asset = $assetService->storeAsset(
                $space,
                $request->file('file'),
                (object) ($validated['metadata'] ?? []),
                (object) ($validated['data'] ?? []),
                $folder,
                $validated['external_id'] ?? null,
                (bool) ($validated['force'] ?? false)
            );

            if (array_key_exists('tags', $validated)) {
                $asset->tags = $validated['tags'] ?? [];
                $asset->save();
            }

            return new AssetResource($asset->load('folder'));
        } catch (DuplicateAssetException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'duplicate_asset',
                'existing_asset' => new AssetResource($e->existingAsset->load('folder')),
            ], 409);
        } catch (\Exception $e) {
            Log::error('Failed to store asset', [
                'space_id' => $space->id,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Failed to store asset.',
            ], 500);
        }
    }

    /**
     * Display the specified asset.
     */
    public function show(Space $space, Asset $asset, AssetUsageService $assetUsageService): AssetResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.view'), 403);

        $asset->load('folder');
        $asset->setAttribute('linked_contents_count', $assetUsageService->getUsageCountForAsset($asset));

        return new AssetResource($asset);
    }

    /**
     * Update the specified asset metadata.
     */
    public function update(
        UpdateAssetRequest $request,
        Space $space,
        Asset $asset,
        AssetService $assetService
    ): AssetResource|JsonResponse {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.manage'), 403);
        $validated = $request->validated();

        if (array_key_exists('folder_id', $validated)) {
            $asset->folder_id = $validated['folder_id'];
        }

        if (array_key_exists('filename', $validated)) {
            $assetService->rename($asset, $validated['filename']);
        }

        if (array_key_exists('metadata', $validated)) {
            $asset->metadata = array_merge(
                $asset->metadata ?? [],
                $validated['metadata'] ?? []
            );
        }

        if (array_key_exists('tags', $validated)) {
            $asset->tags = $validated['tags'] ?? [];
        }

        if (array_key_exists('data', $validated)) {
            $asset->data = $validated['data'];
        }

        if (array_key_exists('external_id', $validated)) {
            $asset->external_id = $validated['external_id'];
        }

        if (array_key_exists('license_expires_at', $validated)) {
            $asset->license_expires_at = $validated['license_expires_at'];
        }

        $asset->save();

        return new AssetResource($asset->load('folder'));
    }

    /**
     * Replace the physical file of an existing asset (keeps ID, busts CDN cache via new filename).
     */
    public function replaceFile(
        Space $space,
        Asset $asset,
        ReplaceAssetFileRequest $request,
        AssetService $assetService
    ): AssetResource|JsonResponse {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.manage'), 403);

        try {
            $asset = $assetService->replaceFile($asset, $request->file('file'), $space);

            return new AssetResource($asset->load('folder'));
        } catch (\Exception $e) {
            Log::error('Failed to replace asset file', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to replace asset file: '.$e->getMessage()], 500);
        }
    }

    /**
     * Upload a custom poster/thumbnail for a non-image asset.
     *
     * The image is shown in place of the generated video frames or the
     * file-type icon.
     */
    public function uploadPoster(
        Space $space,
        Asset $asset,
        UploadAssetPosterRequest $request,
        AssetService $assetService
    ): AssetResource|JsonResponse {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.manage'), 403);

        if (Str::startsWith((string) $asset->mime_type, 'image/')) {
            return response()->json([
                'message' => 'Image assets are their own preview and cannot have a custom poster.',
                'code' => 'poster_not_supported',
            ], 422);
        }

        try {
            $asset = $assetService->setCustomPoster($asset, $request->file('poster'), $space);

            return new AssetResource($asset->load('folder'));
        } catch (\Exception $e) {
            Log::error('Failed to upload asset poster', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to upload poster: '.$e->getMessage()], 500);
        }
    }

    /**
     * Remove a custom poster.
     *
     * Restores the stashed generated video frames when the asset has any.
     */
    public function removePoster(
        Space $space,
        Asset $asset,
        AssetService $assetService
    ): AssetResource|JsonResponse {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.manage'), 403);

        $hasCustomPoster = collect((array) ($asset->metadata['thumbnails'] ?? []))
            ->contains(fn ($thumb) => is_array($thumb) && ! empty($thumb['path']) && ! empty($thumb['custom']));

        if (! $hasCustomPoster) {
            return response()->json([
                'message' => 'This asset has no custom poster to remove.',
                'code' => 'no_custom_poster',
            ], 422);
        }

        try {
            $asset = $assetService->removeCustomPoster($asset);

            return new AssetResource($asset->load('folder'));
        } catch (\Exception $e) {
            Log::error('Failed to remove asset poster', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to remove poster: '.$e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified asset.
     */
    public function destroy(
        Space $space,
        Asset $asset,
        Request $request,
        AssetService $assetService,
        AssetUsageService $assetUsageService
    ): JsonResponse {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'assets.manage'), 403);
        try {
            $linkedContentsCount = $assetUsageService->getUsageCountForAsset($asset);

            if ($linkedContentsCount > 0 && !$request->boolean('force')) {
                return response()->json([
                    'message' => 'Asset is currently linked to content.',
                    'code' => 'asset_in_use',
                    'linked_contents_count' => $linkedContentsCount,
                    'can_force_delete' => true,
                ], 409);
            }

            $result = $assetService->deleteAsset($asset);

            if ($result) {
                return response()->json(null, 204);
            } else {
                return response()->json([
                    'message' => 'Failed to delete asset',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete asset', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the asset',
            ], 500);
        }
    }

    private function attachUsageCounts(iterable $assets, AssetUsageService $assetUsageService): void
    {
        $collection = collect($assets);
        $counts = $assetUsageService->getUsageCountsForAssets($collection);

        foreach ($collection as $asset) {
            $asset->setAttribute('linked_contents_count', $counts[$asset->id] ?? 0);
        }
    }
}
