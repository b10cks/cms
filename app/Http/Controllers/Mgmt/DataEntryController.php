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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            ->paginate($request->get('per_page', 50));

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

        $entry = new DataEntry($request->validated());
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

        return new DataEntryResource($entry);
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

        $entry->fill($request->validated());
        $entry->save();

        $this->clearEntryCache($dataSource);

        return new DataEntryResource($entry);
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

        try {
            $entry->delete();
            $this->clearEntryCache($dataSource);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete data entry', [
                'entry_id' => $entry->id,
                'data_source_id' => $dataSource->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the data entry',
            ], 500);
        }
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

    protected function clearEntryCache(DataSource $dataSource): void
    {
        Cache::forget("data_source:{$dataSource->id}:entries");
    }
}
