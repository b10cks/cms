<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetTagFilter;
use App\Http\Requests\Asset\UpsertAssetTagRequest;
use App\Http\Resources\Management\AssetTagResource;
use App\Models\Management\Space;
use App\Models\Space\AssetTag;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class AssetTagController extends Controller
{
    /**
     * Display a listing of the tags.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_tags.view'), 403);
        $filter = new AssetTagFilter($request->all());

        $tags = AssetTag::filter($filter)->get();

        return AssetTagResource::collection($tags);
    }

    /**
     * Store a newly created tag.
     */
    public function store(Space $space, UpsertAssetTagRequest $request): AssetTagResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_tags.manage'), 403);
        $tag = new AssetTag($request->validated());
        abort_unless($tag->save(), 500, 'Failed to create tag');

        return new AssetTagResource($tag);
    }

    /**
     * Display the specified tag.
     */
    public function show(Space $space, AssetTag $tag): AssetTagResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_tags.view'), 403);

        return new AssetTagResource($tag);
    }

    /**
     * Update the specified tag.
     */
    public function update(UpsertAssetTagRequest $request, Space $space, AssetTag $tag): AssetTagResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_tags.manage'), 403);
        $tag->fill($request->validated());
        $tag->save();

        return new AssetTagResource($tag);
    }

    /**
     * Remove the specified tag.
     */
    public function destroy(Space $space, AssetTag $tag): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'asset_tags.manage'), 403);
        try {
            $tag->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete asset tag', [
                'tag_id' => $tag->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the tag',
            ], 500);
        }
    }
}
