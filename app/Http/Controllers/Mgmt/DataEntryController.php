<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\DataEntryFilter;
use App\Http\Requests\Space\CreateDataEntryRequest;
use App\Http\Requests\Space\UpdateDataEntryRequest;
use App\Http\Resources\Management\DataEntryResource;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Space\ShapeValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Cache;

class DataEntryController extends Controller
{
    /**
     * Display a listing of data entries for a data source.
     *
     * @param Space $space
     * @param DataSource $dataSource
     * @param Request $request
     * @return ResourceCollection
     */
    public function index(Space $space, DataSource $dataSource, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [DataEntry::class, $dataSource, $space]);

        $entries = DataEntry::filter(new DataEntryFilter($request->all()))
            ->where('data_source_id', $dataSource->id)
            ->paginate($this->perPage($request, 50));

        $entries->each(fn (DataEntry $entry) => $entry->setRelation('dataSource', $dataSource));

        return DataEntryResource::collection($entries);
    }

    /**
     * Store a newly created data entry.
     *
     * @param CreateDataEntryRequest $request
     * @param Space $space
     * @param DataSource $dataSource
     * @return DataEntryResource
     */
    public function store(CreateDataEntryRequest $request, Space $space, DataSource $dataSource): DataEntryResource
    {
        $this->authorize('create', [DataEntry::class, $dataSource, $space]);
        $this->validateDimensions($dataSource, $request->input('dimensions', []));

        $entry = new DataEntry($this->encodeShapedValues($dataSource, $request->validated()));
        $entry->dataSource()->associate($dataSource);
        $entry->save();

        $this->clearEntryCache($dataSource);

        return new DataEntryResource($entry);
    }

    /**
     * Display the specified data entry.
     *
     * @param Space $space
     * @param DataSource $dataSource
     * @param DataEntry $entry
     * @return DataEntryResource
     */
    public function show(Space $space, DataSource $dataSource, DataEntry $entry): DataEntryResource
    {
        $this->authorize('view', [$entry, $dataSource, $space]);

        return new DataEntryResource($entry->setRelation('dataSource', $dataSource));
    }

    /**
     * Update the specified data entry.
     *
     * @param UpdateDataEntryRequest $request
     * @param Space $space
     * @param DataSource $dataSource
     * @param DataEntry $entry
     * @return DataEntryResource
     */
    public function update(UpdateDataEntryRequest $request, Space $space, DataSource $dataSource, DataEntry $entry): DataEntryResource
    {
        $this->authorize('update', [$entry, $dataSource, $space]);
        if ($request->has('dimensions')) {
            $this->validateDimensions($dataSource, $request->input('dimensions', []));
        }

        $entry->fill($this->encodeShapedValues($dataSource, $request->validated()));
        $entry->save();

        $this->clearEntryCache($dataSource);

        return new DataEntryResource($entry->setRelation('dataSource', $dataSource));
    }

    /**
     * Remove the specified data entry.
     *
     * @param Space $space
     * @param DataSource $dataSource
     * @param DataEntry $entry
     * @return JsonResponse
     */
    public function destroy(Space $space, DataSource $dataSource, DataEntry $entry): JsonResponse
    {
        $this->authorize('delete', [$entry, $dataSource, $space]);

        $entry->delete();
        $this->clearEntryCache($dataSource);

        return response()->json(null, 204);
    }

    /**
     * Validate that dimensions exist in the data source.
     *
     * @param DataSource $dataSource
     * @param array $dimensions
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validateDimensions(DataSource $dataSource, array|null $dimensions): void
    {
        if (empty($dimensions)) {
            return;
        }

        $availableDimensions = $dataSource->dimensions ?? [];

        foreach (array_keys($dimensions) as $dimension) {
            if (!in_array($dimension, array_column($availableDimensions, 'key'), true)) {
                abort(422, "Dimension '{$dimension}' does not exist in data source '{$dataSource->name}'");
            }
        }
    }

    /**
     * JSON-encode structured value and dimension overrides for shaped sources.
     * A cleared (null) value is stored as an empty string — the column is
     * not nullable — and decoded back to null on output.
     */
    protected function encodeShapedValues(DataSource $dataSource, array $data): array
    {
        if ($dataSource->hasShape()) {
            if (array_key_exists('value', $data)) {
                $data['value'] = ShapeValue::encode($data['value'], $dataSource->shape);
            }

            if (!empty($data['dimensions'])) {
                $data['dimensions'] = array_map(
                    fn ($value) => ShapeValue::encode($value, $dataSource->shape),
                    $data['dimensions']
                );
            }
        }

        if (array_key_exists('value', $data)) {
            $data['value'] ??= '';
        }

        return $data;
    }

    protected function clearEntryCache(DataSource $dataSource): void
    {
        Cache::forget("data_source:{$dataSource->id}:entries");
    }
}
