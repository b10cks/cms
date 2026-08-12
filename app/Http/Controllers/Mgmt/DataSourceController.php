<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\DataSourceFilter;
use App\Http\Requests\Space\CreateDataSourceRequest;
use App\Http\Requests\Space\UpdateDataSourceRequest;
use App\Http\Resources\Management\DataSourceResource;
use App\Models\Management\Space;
use App\Models\Space\DataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Cache;

class DataSourceController extends Controller
{
    /**
     * Display a listing of the data sources.
     *
     * @param Space $space
     * @param Request $request
     * @return ResourceCollection
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('view', $space);

        $dataSources = DataSource::filter(new DataSourceFilter($request->all()))
            ->withCount([
                'entries',
            ])
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return DataSourceResource::collection($dataSources);
    }

    /**
     * Store a newly created data source.
     *
     * @param CreateDataSourceRequest $request
     * @param Space $space
     * @return DataSourceResource
     */
    public function store(CreateDataSourceRequest $request, Space $space): DataSourceResource
    {
        $this->authorize('create', [DataSource::class, $space]);

        $dataSource = new DataSource($request->validated());
        $dataSource->save();

        return new DataSourceResource($dataSource);
    }

    /**
     * Display the specified data source.
     *
     * @param Space $space
     * @param DataSource $dataSource
     * @return DataSourceResource
     */
    public function show(Space $space, DataSource $dataSource): DataSourceResource
    {
        $this->authorize('view', [$dataSource, $space]);

        return new DataSourceResource($dataSource);
    }

    /**
     * Update the specified data source.
     *
     * @param UpdateDataSourceRequest $request
     * @param Space $space
     * @param DataSource $dataSource
     * @return DataSourceResource
     */
    public function update(UpdateDataSourceRequest $request, Space $space, DataSource $dataSource): DataSourceResource
    {
        $this->authorize('update', [$dataSource, $space]);

        $dataSource->fill($request->validated());
        $dataSource->save();

        $this->clearCache($dataSource);

        return new DataSourceResource($dataSource);
    }

    /**
     * Remove the specified data source.
     *
     * @param Space $space
     * @param DataSource $dataSource
     * @return JsonResponse
     */
    public function destroy(Space $space, DataSource $dataSource): JsonResponse
    {
        $this->authorize('delete', [$dataSource, $space]);

        $this->clearCache($dataSource);

        $dataSource->delete();

        return response()->json(null, 204);
    }

    protected function clearCache(DataSource $dataSource): void
    {
        Cache::forget("data_source:{$dataSource->id}:entries");
    }
}
