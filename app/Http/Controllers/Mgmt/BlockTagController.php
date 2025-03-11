<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\BlockTagFilter;
use App\Http\Requests\BlockTag\UpsertBlockTagRequest;
use App\Http\Resources\Management\BlockTagResource;
use App\Models\Management\Space;
use App\Models\Space\BlockTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class BlockTagController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $filter = new BlockTagFilter($request->all());

        $tags = BlockTag::filter($filter)
            ->withCount(['blocks'])
            ->paginate(min(request()->per_page ?? 20, 500));


        return BlockTagResource::collection($tags);
    }

    public function store(Space $space, UpsertBlockTagRequest $request): BlockTagResource
    {
        $tag = new BlockTag($request->validated());
        abort_unless($tag->save(), 500, 'Failed to create block tag');

        return new BlockTagResource($tag->loadCount(['blocks']));
    }

    public function show(Space $space, BlockTag $tag): BlockTagResource
    {
        return new BlockTagResource($tag->loadCount(['blocks']));
    }

    public function update(UpsertBlockTagRequest $request, Space $space, BlockTag $tag): BlockTagResource
    {
        $tag->fill($request->validated());
        abort_unless($tag->save(), 500, 'Failed to update block tag');

        return new BlockTagResource($tag->loadCount(['blocks']));
    }

    public function destroy(Space $space, BlockTag $tag): JsonResponse
    {
        try {
            $tag->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete block tag', [
                'tag_name' => $tag->name,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the block tag',
            ], 500);
        }
    }
}
