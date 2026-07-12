<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\Api\RedirectFilter;
use App\Http\Resources\Api\RedirectResource;
use App\Models\Space\Redirect;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RedirectController extends Controller
{
    /**
     * List the redirect rules of the space as source/target pairs with their
     * HTTP status codes. Frontends typically fetch all pages and build a lookup map.
     *
     * @response AnonymousResourceCollection<LengthAwarePaginator<RedirectResource>>
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = Redirect::filter(RedirectFilter::fromRequest($request))
            ->select(['id', 'source', 'target', 'status_code']);

        $redirects = $query->paginate(
            perPage: $request->get('per_page', 50),
            page: $request->get('page', 1)
        );

        return RedirectResource::collection($redirects);
    }
}
