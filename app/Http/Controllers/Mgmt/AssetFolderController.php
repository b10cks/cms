<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetFolderFilter;
use App\Http\Requests\Asset\StoreAssetFolderRequest;
use App\Http\Requests\Asset\UpdateAssetFolderRequest;
use App\Http\Resources\Management\AssetFolderResource;
use App\Models\Management\Space;
use App\Models\Space\AssetFolder;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class AssetFolderController extends Controller
{
    /**
     * Display a listing of asset folders.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_folders.view'), 403);
        $filter = new AssetFolderFilter($request->all());

        $assetFolders = AssetFolder::filter($filter)
            ->withCount(['children', 'assets'])
            ->get();

        return AssetFolderResource::collection($assetFolders);
    }

    /**
     * Store a newly created asset folder.
     */
    public function store(Space $space, StoreAssetFolderRequest $request): AssetFolderResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_folders.manage'), 403);
        $assetFolder = new AssetFolder;
        $assetFolder->fill($request->validated());
        $assetFolder->save();

        return new AssetFolderResource($assetFolder);
    }

    /**
     * Display the specified asset folder.
     */
    public function show(Space $space, AssetFolder $assetFolder): AssetFolderResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_folders.view'), 403);

        return new AssetFolderResource($assetFolder->load(['parent', 'children'])->loadCount(['children', 'assets']));
    }

    /**
     * Update the specified asset folder.
     */
    public function update(UpdateAssetFolderRequest $request, Space $space, AssetFolder $assetFolder): AssetFolderResource|JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_folders.manage'), 403);
        // Prevent circular references
        if ($request->has('parent_id') && $request->parent_id === $assetFolder->id) {
            return response()->json(['message' => 'Folder cannot be its own parent'], 422);
        }

        if ($request->filled('parent_id') && $this->wouldCreateCircularReference($assetFolder, $request->string('parent_id')->value())) {
            return response()->json(['message' => 'Folder cannot be moved into one of its descendants'], 422);
        }

        $assetFolder->fill($request->validated());
        abort_unless($assetFolder->save(), 500, 'Failed to update folder');

        return new AssetFolderResource($assetFolder->loadCount(['children', 'assets']));
    }

    /**
     * Remove the specified asset folder.
     */
    public function destroy(Space $space, AssetFolder $assetFolder): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_folders.manage'), 403);
        // Check if folder has children or assets
        if ($assetFolder->children()->exists() || $assetFolder->assets()->exists()) {
            return response()->json([
                'message' => 'Cannot delete folder that contains assets or subfolders',
            ], 422);
        }

        try {
            $assetFolder->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete asset folder', [
                'folder_id' => $assetFolder->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the folder',
            ], 500);
        }
    }

    private function wouldCreateCircularReference(AssetFolder $assetFolder, string $parentId): bool
    {
        $currentFolder = AssetFolder::query()->find($parentId);

        while ($currentFolder) {
            if ($currentFolder->id === $assetFolder->id) {
                return true;
            }

            if (!$currentFolder->parent_id) {
                return false;
            }

            $currentFolder = AssetFolder::query()->find($currentFolder->parent_id);
        }

        return false;
    }
}
