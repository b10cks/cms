<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ContentTreeOperationsRequest;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentTreeOperationService;
use Illuminate\Http\JsonResponse;

class ContentTreeOperationsController extends Controller
{
    public function __invoke(
        ContentTreeOperationsRequest $request,
        Space $space,
        ContentTreeOperationService $service,
    ): JsonResponse {
        $this->authorize('viewAny', [Content::class, $space]);

        $operations = $request->validated('operations');
        $created = [];
        $warnings = [];
        $tempIdMap = [];

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

            $result = match ($operation['type']) {
                'create' => $service->createItem(
                    $resolvedParentId
                        ? Content::query()->with('block')->whereNull('deleted_at')->findOrFail($resolvedParentId)
                        : null,
                    $operation,
                    $space,
                    $request->user(),
                ),
                'move' => $service->moveItems($resolvedSelection, $resolvedParentId, $resolvedAfterId, $space),
                'delete' => $service->deleteSubtrees($resolvedSelection, $space),
                'duplicate' => $service->duplicateSubtrees(
                    $resolvedSelection,
                    $resolvedParentId,
                    $resolvedAfterId,
                    $space,
                    $request->user(),
                ),
                'update_block' => $service->updateBlock(
                    Content::query()->whereNull('deleted_at')->findOrFail($resolvedIds[0]),
                    $operation['block_id'],
                    $space,
                    $request->user(),
                ),
            };

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
            ],
        ]);
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
