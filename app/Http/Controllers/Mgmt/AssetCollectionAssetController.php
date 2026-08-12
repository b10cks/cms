<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\AssetFilter;
use App\Http\Resources\Management\AssetResource;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetCollection;
use App\Models\Space\AssetCollectionItem;
use App\Services\Asset\AssetPackageService;
use App\Services\Asset\SmartCollectionRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssetCollectionAssetController extends Controller
{
    public function __construct(private readonly SmartCollectionRuleService $ruleService) {}

    /**
     * List the assets of a collection. Manual collections resolve their
     * explicit items (ordered by position unless the client sorts), smart
     * collections evaluate their stored rules.
     */
    public function index(Space $space, AssetCollection $collection, Request $request): ResourceCollection
    {
        $this->authorizeSpace($space, 'asset_collections.view');

        $filter = new AssetFilter($request->all());
        $query = Asset::filter($filter)->with('folder');

        if ($collection->isSmart()) {
            $this->ruleService->apply($query, $collection->rules ?? []);
        } else {
            $query->whereIn(
                'id',
                AssetCollectionItem::query()
                    ->select('asset_id')
                    ->where('collection_id', $collection->id)
            );

            if (! $request->filled('sort')) {
                $query->orderBy(
                    AssetCollectionItem::query()
                        ->select('position')
                        ->whereColumn('asset_collection_items.asset_id', 'assets.id')
                        ->where('collection_id', $collection->id)
                );
            }
        }

        return AssetResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * Add assets to a manual collection, appended after the current last
     * position. Assets already in the collection are silently skipped.
     */
    public function store(Space $space, AssetCollection $collection, Request $request): JsonResponse
    {
        $this->authorizeSpace($space, 'asset_collections.manage');
        abort_if($collection->isSmart(), 422, 'Smart collections resolve their assets from rules and cannot be modified manually.');

        $validated = $this->validateAssetIds($request, checkExistence: true);

        $collection->getConnection()->transaction(function () use ($collection, $validated) {
            $existing = AssetCollectionItem::query()
                ->where('collection_id', $collection->id)
                ->pluck('asset_id')
                ->all();

            $newAssetIds = array_values(array_diff(array_unique($validated['asset_ids']), $existing));

            if ($newAssetIds === []) {
                return;
            }

            $position = (int) AssetCollectionItem::query()
                ->where('collection_id', $collection->id)
                ->max('position');

            $now = now()->toDateTimeString();
            $rows = [];

            foreach ($newAssetIds as $assetId) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'collection_id' => $collection->id,
                    'asset_id' => $assetId,
                    'position' => ++$position,
                    'added_by_id' => auth()->id(),
                    'created_at' => $now,
                ];
            }

            AssetCollectionItem::query()->insertOrIgnore($rows);
        });

        AssetPackageService::markStaleForCollection($collection->id);

        return response()->json(null, 204);
    }

    /**
     * Remove assets from a manual collection.
     */
    public function destroy(Space $space, AssetCollection $collection, Request $request): JsonResponse
    {
        $this->authorizeSpace($space, 'asset_collections.manage');
        abort_if($collection->isSmart(), 422, 'Smart collections resolve their assets from rules and cannot be modified manually.');

        $validated = $this->validateAssetIds($request);

        AssetCollectionItem::query()
            ->where('collection_id', $collection->id)
            ->whereIn('asset_id', $validated['asset_ids'])
            ->delete();

        AssetPackageService::markStaleForCollection($collection->id);

        return response()->json(null, 204);
    }

    /**
     * Reorder a manual collection: `asset_ids` is the full ordered list,
     * each item's position becomes its array index.
     */
    public function reorder(Space $space, AssetCollection $collection, Request $request): JsonResponse
    {
        $this->authorizeSpace($space, 'asset_collections.manage');
        abort_if($collection->isSmart(), 422, 'Smart collections resolve their assets from rules and cannot be reordered.');

        $validated = $this->validateAssetIds($request);

        // Single CASE-based UPDATE instead of one statement per row — a large
        // manual collection would otherwise hold a transaction open across
        // thousands of sequential updates.
        $assetIds = array_values(array_unique($validated['asset_ids']));
        $cases = [];
        $bindings = [];

        foreach ($assetIds as $position => $assetId) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $assetId;
            $bindings[] = $position;
        }

        $placeholders = implode(', ', array_fill(0, count($assetIds), '?'));

        $collection->getConnection()->update(
            'UPDATE asset_collection_items SET position = CASE asset_id '.implode(' ', $cases).' END'
            .' WHERE collection_id = ? AND asset_id IN ('.$placeholders.')',
            [...$bindings, $collection->id, ...$assetIds],
        );

        return response()->json(null, 204);
    }

    /**
     * @return array{asset_ids: array<int, string>}
     */
    private function validateAssetIds(Request $request, bool $checkExistence = false): array
    {
        $validated = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'asset_ids.*' => ['required', 'string', 'ulid'],
        ]);

        if ($checkExistence) {
            // One whereIn instead of a Rule::exists query per element.
            $ids = array_values(array_unique($validated['asset_ids']));
            $existing = Asset::query()->whereIn('id', $ids)->pluck('id')->all();
            $missing = array_diff($ids, $existing);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'asset_ids' => 'Unknown asset ids: '.implode(', ', $missing),
                ]);
            }
        }

        return $validated;
    }
}
