<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetTagFilter;
use App\Http\Requests\Asset\UpsertAssetTagRequest;
use App\Http\Resources\Management\AssetTagResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AssetTagController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorizeSpace($space, 'asset_tags.view');

        $filter = new AssetTagFilter($request->all());

        $tags = AssetTag::filter($filter)
            ->withCount(['assets'])
            ->paginate($this->perPage($request, 25, 500));

        return AssetTagResource::collection($tags);
    }

    public function store(Space $space, UpsertAssetTagRequest $request): AssetTagResource
    {
        $this->authorizeSpace($space, 'asset_tags.manage');

        $tag = new AssetTag($request->validated());
        abort_unless($tag->save(), 500, 'Failed to create asset tag');

        return new AssetTagResource($tag->loadCount(['assets']));
    }

    public function show(Space $space, AssetTag $tag): AssetTagResource
    {
        $this->authorizeSpace($space, 'asset_tags.view');

        return new AssetTagResource($tag->loadCount(['assets']));
    }

    public function update(UpsertAssetTagRequest $request, Space $space, AssetTag $tag): AssetTagResource
    {
        $this->authorizeSpace($space, 'asset_tags.manage');

        $tag->fill($request->validated());
        abort_unless($tag->save(), 500, 'Failed to update asset tag');

        return new AssetTagResource($tag->loadCount(['assets']));
    }

    public function assignAssets(Request $request, Space $space, AssetTag $tag): JsonResponse
    {
        $this->authorizeSpace($space, 'assets.manage');

        $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['required', 'string'],
        ]);

        foreach ($request->input('asset_ids') as $assetId) {
            $asset = Asset::find($assetId);
            if (!$asset) {
                continue;
            }
            $tags = $asset->tags ?? [];
            if (!in_array($tag->id, $tags)) {
                $tags[] = $tag->id;
                $asset->tags = $tags;
                $asset->save();
            }
        }

        return response()->json(null, 204);
    }

    public function destroy(Space $space, AssetTag $tag): JsonResponse
    {
        $this->authorizeSpace($space, 'asset_tags.manage');

        $tag->delete();

        return response()->json(null, 204);
    }
}
