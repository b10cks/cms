<?php

namespace App\Services\Ai\Changesets;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiRun;
use App\Models\Space\Content;
use App\Services\Ai\Run\ToolError;
use Illuminate\Support\Collection;

/**
 * What AI left on an entry for a person: open conflicts, a field a person changed
 * after the run read it. Reading them opens no document.
 */
final class ContentFlags
{
    /**
     * Conflicts per entry for the tree and for publish counts. One index range read
     * that touches only conflicting rows, so its cost follows the conflicts, not the space.
     *
     * @param  list<string>|null  $ids  only these entries
     * @return array<string, array{conflicts: int}>
     */
    public function counts(?array $ids = null): array
    {
        return AiChangesetItem::query()->toBase()
            ->where('status', AiChangesetItemStatus::Conflict->value)
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->whereNotNull('target_id')
            ->when($ids !== null, fn ($query) => $query->whereIn('target_id', $ids))
            ->groupBy('target_id')
            ->selectRaw('target_id, count(*) as total')
            ->pluck('total', 'target_id')
            ->mapWithKeys(fn ($total, $id) => [(string) $id => ['conflicts' => (int) $total]])
            ->all();
    }

    /**
     * The open conflicts of some entries, named: the field and where to go.
     *
     * @param  list<string>  $ids
     * @return list<array{content_id: string, name: string, language_iso: string|null, flags: list<array{kind: string, path: string|null, field: string|null, thread_id: string|null}>}>
     */
    public function details(array $ids): array
    {
        $items = AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->whereIn('target_id', $ids)
            ->where('status', AiChangesetItemStatus::Conflict->value)
            ->orderBy('id')
            ->get(['id', 'changeset_id', 'target_type', 'target_id', 'path', 'status']);
        if ($items->isEmpty()) {
            return [];
        }

        $contents = Content::query()->with(['block', 'current_version'])->whereIn('id', $items->pluck('target_id')->unique())->get()->keyBy('id');
        $threads = $this->threadIds($items->pluck('changeset_id')->unique()->values()->all());

        return $items->groupBy('target_id')
            ->filter(fn (Collection $group, string $id) => $contents->has($id))
            ->map(function (Collection $group, string $id) use ($contents, $threads) {
                /** @var Content $content */
                $content = $contents[$id];
                $flags = $group->map(fn (AiChangesetItem $item) => [
                    'kind' => 'conflict',
                    'path' => $item->path,
                    'field' => $this->fieldLabel($content, $item->path),
                    'thread_id' => $threads[$item->changeset_id] ?? null,
                ]);

                return [
                    'content_id' => $id,
                    'name' => (string) $content->name,
                    'language_iso' => $content->language_iso,
                    'flags' => $flags->unique(fn (array $flag) => $flag['path'] ?? '')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function fieldLabel(Content $content, ?string $path): ?string
    {
        if ($path === null || $content->block === null) {
            return null;
        }

        try {
            $field = app(ContentTarget::class)->fieldAt($content->block, $content->current_version->content ?? [], $path);

            return (string) ($field['field']['name'] ?? $path);
        } catch (ToolError) {
            return $path;
        }
    }

    /**
     * The thread that holds each changeset: an operation's batch runs have none of their own.
     *
     * @param  list<string>  $changesetIds
     * @return array<string, string|null>
     */
    private function threadIds(array $changesetIds): array
    {
        $changesets = AiChangeset::query()->whereKey($changesetIds)->get(['id', 'run_id', 'operation_id']);
        $operations = AiOperation::query()->whereKey($changesets->pluck('operation_id')->filter()->unique()->values()->all())->pluck('conversation_id', 'id');
        $runs = AiRun::query()->whereKey($changesets->pluck('run_id')->filter()->unique()->values()->all())->pluck('conversation_id', 'id');

        return $changesets->mapWithKeys(fn (AiChangeset $changeset) => [
            $changeset->id => $changeset->operation_id !== null ? ($operations[$changeset->operation_id] ?? null) : ($runs[$changeset->run_id] ?? null),
        ])->all();
    }
}
