<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Block\RestoreBlockVersion;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\BlockVersionFilter;
use App\Http\Requests\BlockVersion\UpdateBlockVersionCommitRequest;
use App\Http\Resources\Management\BlockResource;
use App\Http\Resources\Management\BlockVersionResource;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class BlockVersionController extends Controller
{
    public function index(Space $space, Block $block, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [BlockVersion::class, $space, $block]);

        $versions = BlockVersion::filter(BlockVersionFilter::fromRequest($request))
            ->where('block_id', $block->id)
            ->with(['createdBy', 'parent'])
            ->paginate(min($request->per_page ?? 20, 1000));

        return BlockVersionResource::collection($versions);
    }

    public function show(Space $space, Block $block, BlockVersion $version): BlockVersionResource
    {
        $this->authorize('view', [$version, $space]);

        return new BlockVersionResource($version->load(['createdBy', 'parent']));
    }

    public function update(Space $space, Block $block, BlockVersion $version, UpdateBlockVersionCommitRequest $request): BlockVersionResource
    {
        $this->authorize('updateCommit', [$version, $space]);

        $version->commit_message = $request->validated()['commit_message'];

        if (!$version->save()) {
            Log::error('Failed to update block version commit message', ['version_id' => $version->id]);
            abort(500, 'Failed to update commit message');
        }

        return new BlockVersionResource($version->load(['createdBy', 'parent']));
    }

    public function destroy(Space $space, Block $block, BlockVersion $version): JsonResponse
    {
        $this->authorize('delete', [$version, $space]);

        try {
            $version->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete block version', [
                'version_id' => $version->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the block version',
            ], 500);
        }
    }

    public function restore(Space $space, Block $block, BlockVersion $version, RestoreBlockVersion $restoreAction): BlockResource
    {
        $this->authorize('restore', [$version, $space]);

        $restoredBlock = $restoreAction->execute($version);

        return new BlockResource($restoredBlock);
    }
}
