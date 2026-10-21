<?php

namespace App\Services\Content;

use App\Http\Filters\Mgmt\ContentMenuFilter;
use App\Http\Resources\Management\ContentMenuResource;
use App\Models\Space\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Builds content menu rows, keyed by id, in the shape the menu endpoint returns.
 */
class ContentMenuRows
{
    /**
     * @param  (\Closure(Builder<Content>): mixed)|null  $scope  narrows the menu to some rows, e.g. the ones a tree operation changed
     * @return array<string, array<string, mixed>>
     */
    public function build(ContentMenuFilter $filter, Request $request, ?\Closure $scope = null): array
    {
        $contents = Content::filter($filter)
            ->when($scope !== null, $scope)
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

        $this->attachContentSortValues($contents, $scope !== null);

        return $contents
            ->mapWithKeys(fn (Content $content): array => [
                $content->id => ContentMenuResource::make($content)->resolve($request),
            ])
            ->all();
    }

    /**
     * Attach the content-field sort value (`sv`) to children of folders sorted
     * by a `content.{field}` setting. The heavy content payload never leaves
     * the database: one query per distinct configured field extracts just the
     * JSON value for the affected children, and folders without such a
     * setting cost nothing.
     *
     * A partial menu looks up the settings of parents it does not contain and
     * extracts values for its own rows only.
     *
     * @param  Collection<int, Content>  $contents
     */
    protected function attachContentSortValues(Collection $contents, bool $partial): void
    {
        $parents = $contents->keyBy('id');
        $missingParentIds = $contents
            ->pluck('parent_id')
            ->filter()
            ->unique()
            ->reject(fn (string $parentId): bool => $parents->has($parentId))
            ->values();

        if ($missingParentIds->isNotEmpty()) {
            $parents = $parents->merge(
                Content::query()->whereIn('id', $missingParentIds)->get(['id', 'settings'])->keyBy('id')
            );
        }

        $parentIdsByField = [];

        foreach ($parents as $parent) {
            $field = $parent->settings?->getChildContentSortField();
            if ($field !== null) {
                $parentIdsByField[$field][] = $parent->id;
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
                ->when($partial, fn (Builder $query) => $query->whereIn('contents.id', $contents->modelKeys()))
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
