<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetFilter;
use App\Http\Resources\Management\AssetResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Services\Storage\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class AssetController extends Controller
{
    /**
     * Display a listing of assets.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        $filter = new AssetFilter($request->all());

        $assets = Asset::filter($filter)
            ->paginate($request->get('per_page', 20));

        return AssetResource::collection($assets);
    }

    /**
     * Store a newly created asset.
     */
    public function store(Space $space, Request $request, AssetService $assetService): AssetResource|JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:' . (config('filesystems.max_upload_size', 100) * 1024),
            'folder_id' => 'nullable',
            'metadata' => 'nullable|array',
            'data' => 'nullable',
        ]);

        // If folder_id provided, check it belongs to this space
        $folder = null;
        if ($request->has('folder_id')) {
            $folder = AssetFolder::findOrFail($request->folder_id);
        }

        // Store the asset
        try {
            $asset = $assetService->storeAsset(
                $space,
                $request->file('file'),
                (object)$request->json('metadata', new \StdClass),
                $request->json('data', new \StdClass),
                $folder
            );

            return new AssetResource($asset);
        } catch (\Exception $e) {
            Log::error('Failed to store asset', [
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to store asset: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified asset.
     */
    public function show(Space $space, Asset $asset): AssetResource
    {
        return new AssetResource($asset->load('folder'));
    }

    /**
     * Update the specified asset metadata.
     */
    public function update(Request $request, Space $space, Asset $asset, AssetService $assetService): AssetResource|JsonResponse
    {
        $request->validate([
            'filename' => 'sometimes|string|max:100',
            'folder_id' => 'nullable', // |exists:asset_folders,id',
            'metadata' => 'sometimes|nullable|array',
            'data' => 'sometimes|nullable|array',
            'tags' => 'sometimes|nullable|array',
        ]);

        if ($request->has('folder_id') && $request->folder_id) {
            $asset->folder_id = $request->folder_id;
        }

        if ($request->has('filename')) {
            $assetService->rename($asset, $request->filename);
        }

        if ($request->has('metadata')) {
            $asset->metadata = array_merge(
                $asset->metadata ?? [],
                $request->metadata
            );
        }

        if ($request->has('tags')) {
            $asset->tags = $request->tags;
        }

        // Update tags if provided
        if ($request->has('data')) {
            $asset->data = $request->data;
        }

        $asset->save();

        return new AssetResource($asset);
    }

    /**
     * Remove the specified asset.
     */
    public function destroy(Space $space, Asset $asset, AssetService $assetService): JsonResponse
    {
        try {
            $result = $assetService->deleteAsset($asset);

            if ($result) {
                return response()->json(null, 204);
            } else {
                return response()->json([
                    'message' => 'Failed to delete asset'
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
}
