<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentMenuFilter;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentMenuCache;
use App\Services\Content\ContentMenuRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentMenuController extends Controller
{
    /**
     * Get the content tree structure for menu navigation.
     *
     * @param  Space  $space  The space to get content from
     * @param  Request  $request  The request with filter parameters
     * @return JsonResponse Collection of top-level content with their children
     */
    public function __invoke(
        Space $space,
        Request $request,
        ContentMenuCache $contentMenuCache,
        ContentMenuRows $contentMenuRows,
    ): JsonResponse {
        $this->authorize('viewAny', [Content::class, $space]);

        $menu = $contentMenuCache->remember($space, $request->query(), function () use ($request, $space, $contentMenuRows): array {
            $filter = ContentMenuFilter::fromRequest($request);
            $filter->setSpace($space);

            return $contentMenuRows->build($filter, $request);
        });

        return response()->json([
            'data' => $menu,
        ]);
    }
}
