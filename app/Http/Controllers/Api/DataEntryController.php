<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\Api\DataEntryResource;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DataEntryController
{
    /**
     * @response AnonymousResourceCollection<LengthAwarePaginator<DataEntryResource>>
     */
    public function index(Request $request, DataSource $source): AnonymousResourceCollection
    {
        abort_unless($source?->is_active, 404, 'Data source not found or inactive.');

        $entries = DataEntry::where('data_source_id', $source->id)
            ->paginate(min($request->integer('per_page', 20), 500));

        return DataEntryResource::collection($entries);
    }
}
