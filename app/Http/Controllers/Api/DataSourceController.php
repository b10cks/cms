<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\Api\DataSourceResource;
use App\Models\Space\DataSource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DataSourceController
{
    /**
     * @response AnonymousResourceCollection<LengthAwarePaginator<DataSourceResource>>
     */
    public function index(): AnonymousResourceCollection
    {
        $entries = DataSource::query()
            ->where('is_active', true)
            //            ->filter(DataSourceFilter::fromRequest(request()))
            ->paginate(min(request()->per_page ?? 20, 500));

        return DataSourceResource::collection($entries);
    }

    public function show(DataSource $source)
    {

    }

}
