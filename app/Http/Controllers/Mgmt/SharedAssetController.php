<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\SharedAssetResource;
use App\Models\Management\SharedAsset;
use App\Models\Management\SharedAssetLibrary;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Storage\SharedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class SharedAssetController extends Controller
{
    /**
     * Display a listing of shared assets in a library
     */
    public function index(Request $request, SharedAssetLibrary $library): ResourceCollection
    {
        $sharedAssets = SharedAsset::where('library_id', $library->id)
            ->with(['library', 'sourceSpace'])
            ->paginate($request->get('per_page', 20));

        return SharedAssetResource::collection($sharedAssets);
    }

    /**
     * Share an asset into a library
     */
    public function store(
        Request $request,
        SharedAssetLibrary $library,
        SharedAssetService $sharedAssetService
    ): SharedAssetResource|JsonResponse {
        $validated = $request->validate([
            'asset_id' => 'required|string',
            'shared_name' => 'nullable|string|max:100',
            'shared_description' => 'nullable|string',
            'shared_tags' => 'nullable|array',
            'shared_metadata' => 'nullable|array',
        ]);

        try {
            // Get the current space from context
            $space = request('space');
            if (!$space) {
                return response()->json([
                    'message' => 'No space context available'
                ], 400);
            }

            // Find the asset in the current space
            $asset = Asset::find($validated['asset_id']);
            if (!$asset) {
                return response()->json([
                    'message' => 'Asset not found in current space'
                ], 404);
            }

            // Share the asset
            $sharedAsset = $sharedAssetService->shareAsset(
                $asset,
                $library,
                $validated['shared_name'] ?? null,
                $validated['shared_description'] ?? null,
                $validated['shared_tags'] ?? null,
                $validated['shared_metadata'] ?? null
            );

            return new SharedAssetResource($sharedAsset->load(['library', 'sourceSpace']));
        } catch (\Exception $e) {
            Log::error('Failed to share asset', [
                'library_id' => $library->id,
                'asset_id' => $validated['asset_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to share asset: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified shared asset
     */
    public function show(SharedAssetLibrary $library, SharedAsset $sharedAsset): SharedAssetResource|JsonResponse
    {
        // Ensure shared asset belongs to library
        if ($sharedAsset->library_id !== $library->id) {
            return response()->json([
                'message' => 'Shared asset not found in this library'
            ], 404);
        }

        // Record access
        $sharedAsset->recordAccess();

        return new SharedAssetResource($sharedAsset->load(['library', 'sourceSpace', 'permissions']));
    }

    /**
     * Update the specified shared asset metadata
     */
    public function update(
        Request $request,
        SharedAssetLibrary $library,
        SharedAsset $sharedAsset
    ): SharedAssetResource|JsonResponse {
        // Ensure shared asset belongs to library
        if ($sharedAsset->library_id !== $library->id) {
            return response()->json([
                'message' => 'Shared asset not found in this library'
            ], 404);
        }

        $validated = $request->validate([
            'shared_name' => 'nullable|string|max:100',
            'shared_description' => 'nullable|string',
            'shared_tags' => 'nullable|array',
            'shared_metadata' => 'nullable|array',
        ]);

        try {
            $sharedAsset->update($validated);

            return new SharedAssetResource($sharedAsset->load(['library', 'sourceSpace']));
        } catch (\Exception $e) {
            Log::error('Failed to update shared asset', [
                'shared_asset_id' => $sharedAsset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update shared asset: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified shared asset from the library
     */
    public function destroy(
        SharedAssetLibrary $library,
        SharedAsset $sharedAsset,
        SharedAssetService $sharedAssetService
    ): JsonResponse {
        // Ensure shared asset belongs to library
        if ($sharedAsset->library_id !== $library->id) {
            return response()->json([
                'message' => 'Shared asset not found in this library'
            ], 404);
        }

        try {
            $result = $sharedAssetService->unshareAsset($sharedAsset);

            if ($result) {
                return response()->json(null, 204);
            } else {
                return response()->json([
                    'message' => 'Failed to unshare asset'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Failed to unshare asset', [
                'shared_asset_id' => $sharedAsset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while unsharing the asset',
            ], 500);
        }
    }

    /**
     * Get all shared assets accessible by the current space
     */
    public function accessible(Request $request, Space $space, SharedAssetService $sharedAssetService): ResourceCollection
    {
        try {
            $libraryId = $request->query('library_id');
            $library = $libraryId ? SharedAssetLibrary::find($libraryId) : null;

            $sharedAssets = $sharedAssetService->getSharedAssets($space, $library);

            return SharedAssetResource::collection($sharedAssets);
        } catch (\Exception $e) {
            Log::error('Failed to get accessible shared assets', [
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return SharedAssetResource::collection(collect([]));
        }
    }
}
