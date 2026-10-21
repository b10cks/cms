<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentMenuFilter;
use App\Http\Requests\Content\ContentTreeOperationsRequest;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentMenuRows;
use App\Services\Content\ContentTreeOperationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentTreeOperationsController extends Controller
{
    /**
     * Runs a batch of content tree operations
     *
     * Operations run in order, and a later operation can refer to the
     * `temp_id` of an earlier `create`. Returns the ids `created` for those
     * temp ids, any `warnings`, and in `menu` the content menu rows the batch
     * changed, keyed by id in the shape of the content menu endpoint: moved,
     * created, duplicated and re-blocked entries, every sibling under a parent
     * they left or joined, and those parents. Deleted entries are not listed.
     */
    public function __invoke(
        ContentTreeOperationsRequest $request,
        Space $space,
        ContentTreeOperationService $service,
        ContentMenuRows $menuRows,
    ): JsonResponse {
        $this->authorize('viewAny', [Content::class, $space]);

        $operations = $request->validated('operations');
        $created = [];
        $warnings = [];
        $tempIdMap = [];
        // Entries whose menu row changed, and parents whose children did (null is the root).
        $changedIds = [];
        $changedParentIds = [];

        foreach ($operations as $operation) {
            $resolvedIds = $this->resolveIds($operation, $tempIdMap);
            $resolvedSelection = \in_array($operation['type'], ['move', 'delete', 'duplicate'], true)
                ? $service->resolveOrderedRootSelection($resolvedIds)
                : $resolvedIds;

            match ($operation['type']) {
                'create' => $this->authorize('create', [Content::class, $space]),
                'move', 'update_block' => $this->authorizeForIds($resolvedSelection, $space, 'update'),
                'delete' => $this->authorizeForIds($resolvedSelection, $space, 'delete'),
                'duplicate' => $this->authorizeDuplicate($resolvedSelection, $space),
                default => null,
            };

            $resolvedParentId = $this->resolveId(data_get($operation, 'parent_id'), $tempIdMap);
            $resolvedAfterId = $this->resolveId(data_get($operation, 'after_id'), $tempIdMap);

            if ($operation['type'] === 'move') {
                $changedParentIds = [
                    ...$changedParentIds,
                    $resolvedParentId,
                    ...Content::query()->whereIn('id', $resolvedSelection)->pluck('parent_id')->all(),
                ];
            }

            $result = match ($operation['type']) {
                'create' => $service->createItem(
                    $resolvedParentId
                        ? Content::query()->with('block')->whereNull('deleted_at')->findOrFail($resolvedParentId)
                        : null,
                    $operation,
                    $space,
                    $request->user(),
                ),
                'move' => $service->moveItems(
                    $resolvedSelection,
                    $resolvedParentId,
                    $resolvedAfterId,
                    $space,
                    data_get($operation, 'position'),
                ),
                'delete' => $service->deleteSubtrees($resolvedSelection, $space),
                'duplicate' => $service->duplicateSubtrees(
                    $resolvedSelection,
                    $resolvedParentId,
                    $resolvedAfterId,
                    $space,
                    $request->user(),
                    data_get($operation, 'position'),
                ),
                'update_block' => $service->updateBlock(
                    Content::query()->whereNull('deleted_at')->findOrFail($resolvedIds[0]),
                    $operation['block_id'],
                    $space,
                    $request->user(),
                ),
            };

            if ($operation['type'] === 'update_block') {
                $changedIds[] = $resolvedIds[0];
            } elseif (\in_array($operation['type'], ['create', 'duplicate'], true)) {
                $roots = collect($result['created'] ?? [])->whereNull('i18n_parent_id');
                $changedParentIds = [...$changedParentIds, ...$roots->pluck('parent_id')->all()];
                if ($operation['type'] === 'duplicate') {
                    $changedIds = [...$changedIds, ...$service->collectCanonicalSubtreeIds($roots->pluck('id')->all())];
                }
            }

            foreach ($result['warnings'] ?? [] as $warning) {
                $warnings[] = $warning;
            }

            if ($operation['type'] === 'create') {
                $canonicalCreated = collect($result['created'] ?? [])->firstWhere('i18n_parent_id', null);
                if ($canonicalCreated && isset($operation['temp_id'])) {
                    $tempIdMap[$operation['temp_id']] = $canonicalCreated->id;
                    $created[] = [
                        'temp_id' => $operation['temp_id'],
                        'id' => $canonicalCreated->id,
                    ];
                }
            }
        }

        return response()->json([
            'data' => [
                'created' => $created,
                'warnings' => $warnings,
                'menu' => $this->changedMenuRows($menuRows, $space, $request, $changedIds, $changedParentIds) ?: new \stdClass,
            ],
        ]);
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string|null>  $parentIds
     * @return array<string, array<string, mixed>>
     */
    protected function changedMenuRows(
        ContentMenuRows $menuRows,
        Space $space,
        Request $request,
        array $ids,
        array $parentIds,
    ): array {
        $touchesRoot = \in_array(null, $parentIds, true);
        $parentIds = array_values(array_unique(array_filter($parentIds)));
        $ids = array_values(array_unique([...$ids, ...$parentIds]));

        if ($ids === [] && ! $touchesRoot) {
            return [];
        }

        // The menu as the tree loads it: no filters, default language.
        $filter = new ContentMenuFilter([]);
        $filter->setSpace($space);

        return $menuRows->build($filter, $request, fn (Builder $query) => $query->where(
            fn (Builder $query) => $query
                ->whereIn('id', $ids)
                ->orWhereIn('parent_id', $parentIds)
                ->when($touchesRoot, fn (Builder $query) => $query->orWhereNull('parent_id'))
        ));
    }

    protected function authorizeForIds(array $ids, Space $space, string $ability): void
    {
        $contents = Content::query()
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get();

        foreach ($contents as $content) {
            $this->authorize($ability, [$content, $space]);
        }
    }

    protected function authorizeDuplicate(array $ids, Space $space): void
    {
        $this->authorize('create', [Content::class, $space]);
        $this->authorizeForIds($ids, $space, 'view');
    }

    protected function resolveIds(array $operation, array $tempIdMap): array
    {
        return collect($operation['ids'] ?? [data_get($operation, 'id')])
            ->filter()
            ->map(fn (string $id) => $this->resolveId($id, $tempIdMap))
            ->filter()
            ->values()
            ->all();
    }

    protected function resolveId(?string $id, array $tempIdMap): ?string
    {
        if ($id === null) {
            return null;
        }

        return $tempIdMap[$id] ?? $id;
    }
}
