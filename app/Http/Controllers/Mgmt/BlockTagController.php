<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\BlockTagFilter;
use App\Http\Requests\BlockTag\UpsertBlockTagRequest;
use App\Http\Resources\Management\BlockTagResource;
use App\Models\Management\Space;
use App\Models\Space\BlockTag;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BlockTagController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'blocks.view'), 403);

        $filter = new BlockTagFilter($request->all());

        $tags = BlockTag::filter($filter)
            ->withCount(['blocks'])
            ->paginate($this->perPage($request, 20, 500));

        return BlockTagResource::collection($tags);
    }

    public function store(Space $space, UpsertBlockTagRequest $request): BlockTagResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'blocks.manage'), 403);

        $tag = new BlockTag($request->validated());
        abort_unless($tag->save(), 500, 'Failed to create block tag');

        return new BlockTagResource($tag->loadCount(['blocks']));
    }

    public function show(Space $space, BlockTag $tag): BlockTagResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'blocks.view'), 403);

        return new BlockTagResource($tag->loadCount(['blocks']));
    }

    public function update(UpsertBlockTagRequest $request, Space $space, BlockTag $tag): BlockTagResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'blocks.manage'), 403);

        $tag->fill($request->validated());
        abort_unless($tag->save(), 500, 'Failed to update block tag');

        return new BlockTagResource($tag->loadCount(['blocks']));
    }

    public function destroy(Space $space, BlockTag $tag): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'blocks.manage'), 403);

        $tag->delete();

        return response()->json(null, 204);
    }
}
