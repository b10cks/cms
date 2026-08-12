<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AssetResource;
use App\Http\Resources\Management\AssetVersionResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetVersion;
use App\Services\Storage\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AssetVersionController extends Controller
{
    /**
     * List the version history of an asset, most recent first.
     */
    public function index(Space $space, Asset $asset, Request $request): ResourceCollection
    {
        $this->authorizeSpace($space, 'assets.view');

        $versions = AssetVersion::query()
            ->where('asset_id', $asset->id)
            ->with('createdBy')
            ->orderByDesc('version_number')
            ->paginate($request->integer('per_page', 20));

        // The parent asset is already loaded from the route; reuse it instead
        // of triggering a query (or lazy-loading, which is disabled) per version.
        $versions->getCollection()->each(fn (AssetVersion $version) => $version->setRelation('asset', $asset));

        return AssetVersionResource::collection($versions);
    }

    /**
     * Restore an asset to a previous version's file + metadata. The current
     * state is itself snapshotted first, so this is non-destructive.
     */
    public function restore(
        Space $space,
        Asset $asset,
        AssetVersion $version,
        AssetService $assetService
    ): AssetResource|JsonResponse {
        $this->authorizeSpace($space, 'assets.manage');
        abort_unless($version->asset_id === $asset->id, 404);

        try {
            $asset = $assetService->restoreVersion($asset, $version, $space);

            return new AssetResource($asset->load('folder'));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to restore asset version: '.$e->getMessage(),
            ], 500);
        }
    }
}
