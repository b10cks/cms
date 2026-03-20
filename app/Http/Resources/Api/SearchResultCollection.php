<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SearchResultCollection extends ResourceCollection
{
    public $collects = SearchResultResource::class;

    public function toArray(Request $request): array
    {
        return [
            'meta' => [
                'query' => $this->additional['query'] ?? '',
                'total' => $this->additional['total'] ?? $this->collection->count(),
                'limit' => $this->additional['limit'] ?? $this->collection->count(),
                'offset' => $this->additional['offset'] ?? 0,
            ],
            'data' => SearchResultResource::collection($this->collection)->resolve($request),
        ];
    }
}
