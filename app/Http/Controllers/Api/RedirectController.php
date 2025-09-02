<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\Api\RedirectFilter;
use App\Http\Resources\Api\RedirectResource;
use App\Models\Space\Redirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedirectController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = Redirect::filter(RedirectFilter::fromRequest($request))
            ->select(['id', 'source', 'target', 'status_code']);

        $redirects = $query->paginate(
            perPage: $request->get('per_page', 50),
            page: $request->get('page', 1)
        );

        return RedirectResource::collection($redirects)->response();
    }
}
