<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentMenuFilter;
use App\Http\Resources\Management\ContentMenuResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentMenuCache;
use Illuminate\Database\Eloquent\Collection;
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
                    'position',
                    'name',
                    'slug',
                    'block_id',
                    'settings',
                    'current_version_id',
                    'published_version_id',
                    'published_at',
                    'created_at',
                    'updated_at',
                ]);

            $this->attachContentSortValues($contents);

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

    /**
     * Attach the content-field sort value (`sv`) to children of folders sorted
     * by a `content.{field}` setting. The heavy content payload never leaves
     * the database: one query per distinct configured field extracts just the
     * JSON value for the affected children — and folders without such a
     * setting cost nothing.
     *
     * @param  Collection<int, Content>  $contents
     */
    protected function attachContentSortValues($contents): void
    {
        $parentIdsByField = [];

        foreach ($contents as $content) {
            $field = $content->settings?->getChildContentSortField();
            if ($field !== null) {
                $parentIdsByField[$field][] = $content->id;
            }
        }

        if ($parentIdsByField === []) {
            return;
        }

        $values = [];

        foreach ($parentIdsByField as $field => $parentIds) {
            $query = Content::query()
                ->join('content_versions', 'contents.current_version_id', '=', 'content_versions.id')
                ->whereIn('contents.parent_id', $parentIds)
                ->toBase();

            $extract = $query->getGrammar()->wrap('content_versions.content->'.$field);

            $idColumn = $query->getGrammar()->wrap('contents.id');

            $rows = $query
                ->selectRaw("{$idColumn} as id, {$extract} as sort_value")
                ->get();

            foreach ($rows as $row) {
                if ($row->sort_value !== null) {
                    $values[$row->id] = $row->sort_value;
                }
            }
        }

        foreach ($contents as $content) {
            if (\array_key_exists($content->id, $values)) {
                $content->menu_sort_value = $values[$content->id];
            }
        }
    }
}
