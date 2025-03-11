<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\BlockFilter;
use App\Http\Requests\Block\CreateBlockRequest;
use App\Http\Requests\Block\UpdateBlockRequest;
use App\Http\Resources\Management\BlockResource;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class BlockController extends Controller
{
    /**
     * Display a listing of the blocks.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [Block::class, $space]);
        $blocks = Block::filter(BlockFilter::fromRequest($request))
            ->with(['folder'])
            ->paginate();

        return BlockResource::collection($blocks);
    }

    /**
     * Store a newly created block in storage.
     */
    public function store(Space $space, CreateBlockRequest $request): BlockResource
    {
        $this->authorize('create', [Block::class, $space]);

        $data = $request->validated();

        $block = Block::create($data);

        if (isset($data['folder_id']) && !empty($data['folder_id'])) {
            $folder = BlockFolder::findOrFail($data['folder_id']);
            $block->folder()->associate($folder);
            $block->save();
        }

        return new BlockResource($block->load('folder'));
    }

    /**
     * Display the specified block.
     */
    public function show(Space $space, Block $block): BlockResource
    {
        $this->authorize('view', [$block, $space]);

        return new BlockResource($block->load('folder'));
    }

    /**
     * Update the specified block in storage.
     */
    public function update(Space $space, UpdateBlockRequest $request, Block $block): BlockResource
    {
        $this->authorize('update', [$block, $space]);

        $block->fill($request->validated());

        if (!$block->save()) {
            Log::error('Failed to update block', ['block_id' => $block->id]);
            abort(500, 'Failed to update block');
        }

        // Handle folder association
        if ($request->has('folder_id')) {
            if (empty($request->folder_id)) {
                $block->folder()->dissociate();
            } else {
                $folder = BlockFolder::findOrFail($request->folder_id);
                $block->folder()->associate($folder);
            }
            $block->save();
        }

        return new BlockResource($block->load('folder'));
    }

    /**
     * Remove the specified block from storage.
     */
    public function destroy(Space $space, Block $block): JsonResponse
    {
        $this->authorize('delete', [$block, $space]);

        try {
            $block->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete block', [
                'block_id' => $block->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the block',
            ], 500);
        }
    }
}
