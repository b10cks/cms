<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Asset\EnsureAssetFolderPaths;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asset\EnsureAssetFolderPathsRequest;
use App\Http\Resources\Management\AssetFolderResource;
use App\Models\Management\Space;
use Illuminate\Http\JsonResponse;

/**
 * Mirrors a dropped folder tree into asset folders: every requested path is
 * resolved to a folder id, creating missing folders along the way.
 */
class EnsureAssetFolderPathsController extends Controller
{
    public function __invoke(
        EnsureAssetFolderPathsRequest $request,
        Space $space,
        EnsureAssetFolderPaths $action,
    ): JsonResponse {
        $this->authorizeSpace($space, 'asset_folders.manage');

        $result = $action->execute(
            $space,
            $request->validated('parent_id'),
            $request->validated('paths'),
        );

        return response()->json([
            'paths' => $result['paths'],
            'folders' => AssetFolderResource::collection($result['folders']),
            'renamed' => $result['renamed'],
        ]);
    }
}
