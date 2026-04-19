<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentMenuFilter;
use App\Http\Resources\Management\ContentMenuResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentMenuCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentMenuController extends Controller
{
    /**
     * Get the content tree structure for menu navigation.
     *
     * @param Space $space The space to get content from
     * @param Request $request The request with filter parameters
     * @return JsonResponse Collection of top-level content with their children
     */
    public function __invoke(
        Space $space,
        Request $request,
        ContentMenuCache $contentMenuCache,
    ): JsonResponse {
        $this->authorize('viewAny', [Content::class, $space]);

        $menu = $contentMenuCache->remember($space, $request->query(), function () use ($request, $space): array {
            $filter = ContentMenuFilter::fromRequest($request);
            $filter->setSpace($space);

            $contents = Content::filter($filter)
                ->withCount(['children'])
                ->with([
                    'block:id,type,icon,color',
                    'i18n_children',
                ])
                ->get([
                    'id',
                    'parent_id',
                    'name',
                    'slug',
                    'block_id',
                    'settings',
                    'published_at',
                    'updated_at',
                ]);

            return $contents
                ->mapWithKeys(fn (Content $content): array => [
                    $content->id => ContentMenuResource::make($content)->resolve($request),
                ])
                ->all();
        });

        return response()->json([
            'data' => $menu,
        ]);
    }
}
