<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\BlockFolderFilter;
use App\Http\Requests\BlockFolder\UpsertBlockFolderRequest;
use App\Http\Resources\Management\BlockFolderResource;
use App\Models\Management\Space;
use App\Models\Space\BlockFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class BlockFolderController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorizeSpace($space, 'blocks.view');

        $filter = new BlockFolderFilter($request->all());

        $folders = BlockFolder::filter($filter)
            ->withCount(['blocks'])
            ->get();

        return BlockFolderResource::collection($folders);
    }

    public function store(Space $space, UpsertBlockFolderRequest $request): BlockFolderResource
    {
        $this->authorizeSpace($space, 'blocks.manage');

        $folder = new BlockFolder($request->validated());
        abort_unless($folder->save(), 500, 'Failed to create block folder');

        return new BlockFolderResource($folder->loadCount(['blocks']));
    }

    public function show(Space $space, BlockFolder $folder): BlockFolderResource
    {
        $this->authorizeSpace($space, 'blocks.view');

        return new BlockFolderResource($folder->loadCount(['blocks']));
    }

    public function update(UpsertBlockFolderRequest $request, Space $space, BlockFolder $folder): BlockFolderResource
    {
        $this->authorizeSpace($space, 'blocks.manage');

        $folder->fill($request->validated());
        abort_unless($folder->save(), 500, 'Failed to update block folder');

        return new BlockFolderResource($folder->loadCount(['blocks']));
    }

    public function destroy(Space $space, BlockFolder $folder): JsonResponse
    {
        $this->authorizeSpace($space, 'blocks.manage');

        if ($folder->blocks()->exists()) {
            return response()->json([
                'message' => 'Cannot delete folder that contains blocks',
            ], 422);
        }

        try {
            $folder->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete block folder', [
                'folder_id' => $folder->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the block folder',
            ], 500);
        }
    }
}
