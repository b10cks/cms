<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DataSourceResource;
use App\Models\Space\DataSource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DataSourceController extends Controller
{
    /**
     * List the data sources of the space that are marked as available for the API.
     *
     * @response AnonymousResourceCollection<LengthAwarePaginator<DataSourceResource>>
     */
    public function index(): AnonymousResourceCollection
    {
        $entries = DataSource::query()
            ->where('is_active', true)
            //            ->filter(DataSourceFilter::fromRequest(request()))
            ->paginate($this->perPage(request(), 20, 500));

        return DataSourceResource::collection($entries);
    }

    public function show(DataSource $source) {}
}
