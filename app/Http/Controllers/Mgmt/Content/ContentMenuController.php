<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentMenuFilter;
use App\Http\Resources\Management\ContentMenuResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContentMenuController extends Controller
{
    /**
     * Get the content tree structure for menu navigation.
     *
     * @param Space $space The space to get content from
     * @param Request $request The request with filter parameters
     * @return ResourceCollection Collection of top-level content with their children
     */
    public function __invoke(Space $space, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Content::class, $space]);

        $filter = ContentMenuFilter::fromRequest($request);
        $filter->setSpace($space);
        $query = Content::filter($filter)
            ->withCount(['children'])
            ->with(['block', 'i18n_children']);

        $contents = $query->get(['id', 'parent_id', 'name', 'slug', 'full_slug', 'block_id', 'settings', 'published_at', 'created_at', 'updated_at'])
            ->keyBy('id');

        return ContentMenuResource::collection($contents);
    }
}
