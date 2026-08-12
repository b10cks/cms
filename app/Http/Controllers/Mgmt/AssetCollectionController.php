<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetCollectionFilter;
use App\Http\Requests\Asset\UpsertAssetCollectionRequest;
use App\Http\Resources\Management\AssetCollectionResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetCollection;
use App\Services\Asset\AssetPackageService;
use App\Services\Asset\SmartCollectionRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AssetCollectionController extends Controller
{
    public function __construct(private readonly SmartCollectionRuleService $ruleService) {}

    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorizeSpace($space, 'asset_collections.view');

        $filter = new AssetCollectionFilter($request->all());

        $collections = AssetCollection::filter($filter)
            ->withCount('items')
            ->paginate($this->perPage($request));

        // Smart collection counts are query-derived and too expensive for a
        // listing — they stay null here and are computed on show.
        foreach ($collections->getCollection() as $collection) {
            $collection->setAttribute(
                'assets_count',
                $collection->isSmart() ? null : $collection->items_count
            );
        }

        return AssetCollectionResource::collection($collections);
    }

    public function store(Space $space, UpsertAssetCollectionRequest $request): AssetCollectionResource
    {
        $this->authorizeSpace($space, 'asset_collections.manage');

        $validated = $this->prepareAttributes($request->validated());

        $collection = new AssetCollection($validated);
        $collection->created_by_id = auth()->id();
        abort_unless($collection->save(), 500, 'Failed to create asset collection');

        return new AssetCollectionResource($this->withAssetsCount($collection));
    }

    public function show(Space $space, AssetCollection $collection): AssetCollectionResource
    {
        $this->authorizeSpace($space, 'asset_collections.view');

        $collection->load('coverAsset');

        return new AssetCollectionResource($this->withAssetsCount($collection));
    }

    public function update(
        UpsertAssetCollectionRequest $request,
        Space $space,
        AssetCollection $collection
    ): AssetCollectionResource {
        $this->authorizeSpace($space, 'asset_collections.manage');

        $collection->fill($this->prepareAttributes($request->validated(), $collection));
        abort_unless($collection->save(), 500, 'Failed to update asset collection');

        if ($collection->wasChanged('rules')) {
            AssetPackageService::markStaleForCollection($collection->id);
        }

        return new AssetCollectionResource($this->withAssetsCount($collection));
    }

    public function destroy(Space $space, AssetCollection $collection): JsonResponse
    {
        $this->authorizeSpace($space, 'asset_collections.manage');

        // Soft-delete only, keeping the items — restoring the collection
        // restores its membership. Packages built from it must not keep
        // serving a deleted collection's assets, though.
        $collection->delete();

        AssetPackageService::markStaleForCollection($collection->id);

        return response()->json(null, 204);
    }

    /**
     * Normalize the validated payload: smart collections require rules
     * (validated and normalized by the rule service), manual collections
     * never store any.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function prepareAttributes(array $validated, ?AssetCollection $collection = null): array
    {
        $type = $validated['type'] ?? $collection?->type ?? AssetCollection::TYPE_MANUAL;

        if ($type === AssetCollection::TYPE_SMART) {
            $rules = array_key_exists('rules', $validated) ? $validated['rules'] : $collection?->rules;
            abort_if(empty($rules), 422, 'Smart collections require rules.');

            $validated['rules'] = $this->ruleService->validate($rules);
        } else {
            $validated['rules'] = null;
        }

        $validated['type'] = $type;

        return $validated;
    }

    private function withAssetsCount(AssetCollection $collection): AssetCollection
    {
        if ($collection->isSmart()) {
            $count = $this->ruleService
                ->apply(Asset::query(), $collection->rules ?? [])
                ->count();
        } else {
            $count = $collection->items()->count();
        }

        return $collection->setAttribute('assets_count', $count);
    }
}
