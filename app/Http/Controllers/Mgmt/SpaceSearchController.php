<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\SearchDriver;
use App\Http\Controllers\Controller;
use App\Jobs\Space\ReindexSpaceJob;
use App\Models\Management\Space;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class SpaceSearchController extends Controller
{
    public function __construct(
        protected SearchService $searchService
    ) {
    }

    public function update(Space $space, Request $request): JsonResponse
    {
        $this->authorize('update', $space);

        $validated = $request->validate([
            'search_driver' => ['required', new Enum(SearchDriver::class)]
        ]);

        $newDriver = SearchDriver::from($validated['search_driver']);

        $this->searchService->switchDriver($space, $newDriver);

        ReindexSpaceJob::dispatch($space);

        return response()->json([
            'message' => 'Search driver updated successfully. Reindexing in progress.',
            'search_driver' => $newDriver->value
        ]);
    }

    public function reindex(Space $space): JsonResponse
    {
        $this->authorize('update', $space);

        ReindexSpaceJob::dispatch($space);

        return response()->json([
            'message' => 'Space reindexing started successfully.'
        ]);
    }
}
