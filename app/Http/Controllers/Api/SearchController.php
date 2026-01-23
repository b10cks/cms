<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SearchResultResource;
use App\Models\Management\Space;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:1|max:500',
            'limit' => 'sometimes|integer|min:1|max:100',
            'offset' => 'sometimes|integer|min:0',
        ]);

        $space = app('currentSpace');

        if (!$space instanceof Space) {
            abort(404, 'Space not found');
        }

        $query = $validated['q'];
        $limit = $validated['limit'] ?? 20;
        $offset = $validated['offset'] ?? 0;

        $results = $this->searchService->search($space, $query, $limit, $offset);

        return response()->json([
            'query' => $query,
            'total' => $results['total'],
            'limit' => $limit,
            'offset' => $offset,
            'results' => SearchResultResource::collection($results['results']),
        ]);
    }
}
