<?php

namespace App\Services\Ai\Changesets;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Threads\AiThreads;
use App\Services\Ai\Tools\ProposalTool;
use Illuminate\Support\Str;

/**
 * Records a run's changes into its changeset: one per run, or one per operation
 * shared by all its batch runs. In a batch every item links the ledger entry it
 * accounts for. A proposal for an entry that is one piece marks it `proposed` at
 * once, so a crash after the proposal never queues the entry again. Content tools
 * write to the draft right after recording and report the outcome themselves. An
 * entry worked in parts is never done by one change: its tool reports each part
 * it covers, and a crash repeats the call. A derived change (a locale entry, a
 * translation row, a folder) must carry `operation_item_id` from
 * OperationContext::derivedFrom() for the same reason.
 */
class ChangesetRecorder
{
    public function __construct(private readonly AiThreads $threads) {}

    /**
     * Store items, replacing a still pending change of the same field so the model
     * can correct itself. A conflict stays: it is a person's to decide, and the
     * write tools refuse the field until then ({@see ProposalTool::leftForPerson()}).
     *
     * @param  list<array<string, mixed>>  $items  item attributes without changeset; status defaults to pending
     * @return list<AiChangesetItem>
     *
     * @throws ToolError when a batch change names no entry of its batch
     */
    public function record(AiRun $run, array $items, bool $inline = false): array
    {
        $direct = [];
        if ($run->operation_id !== null && ($run->setup['operation']['phase'] ?? null) === OperationContext::PHASE_BATCH) {
            $operationParts = AiOperation::query()->find($run->operation_id)?->expectedParts();
            foreach ($items as $index => $attributes) {
                if (isset($attributes['operation_item_id'])) {
                    continue;
                }

                $entry = ($attributes['target_id'] ?? null) === null ? null : AiOperationItem::query()
                    ->where('operation_id', $run->operation_id)
                    ->where('run_id', $run->id)
                    ->where('target_type', $attributes['target_type'])
                    ->where('target_id', $attributes['target_id'])
                    ->first(['id', 'detail']);
                if ($entry === null) {
                    throw new ToolError('This change is not on an entry of this batch. Change the entries under `targets`, or derive new entries from them with the tools made for it.');
                }

                $items[$index]['operation_item_id'] = (int) $entry->id;
                if ($attributes['target_type'] !== AiChangesetItem::TARGET_CONTENT && $entry->expectedParts($operationParts) === null) {
                    $direct[$index] = (int) $entry->id;
                }
            }
        }

        $changeset = AiChangeset::query()->firstOrCreate($run->operation_id !== null ? ['operation_id' => $run->operation_id] : ['run_id' => $run->id], [
            'run_id' => $run->id,
            'space_id' => $run->space_id,
            'actor_id' => $run->actor_id,
            'title' => $this->title($run),
            'inline' => $inline,
        ]);
        // An operation's planned structure opens its changeset before the first batch run.
        if ($changeset->run_id === null) {
            $changeset->forceFill(['run_id' => $run->id])->save();
        }

        $created = [];
        foreach ($items as $index => $attributes) {
            if (($attributes['path'] ?? null) !== null) {
                $changeset->items()
                    ->where('status', AiChangesetItemStatus::Pending->value)
                    ->where('target_type', $attributes['target_type'])
                    ->where('target_id', $attributes['target_id'])
                    ->where('path', $attributes['path'])
                    ->delete();
            }

            $created[] = $changeset->items()->create([
                'status' => AiChangesetItemStatus::Pending,
                ...$attributes,
                // Domain review flags keep the item out of group accepts like any other flag.
                'flags' => ($attributes['flags'] ?? null) ?: (($attributes['review']['flags'] ?? null) ?: null),
            ]);

            if (isset($direct[$index])) {
                AiOperationItem::markProposed([$direct[$index]]);
            }
        }

        $changeset->refreshCounts();

        // Only a background changeset can put its thread up for a person.
        if (! $changeset->inline) {
            $this->threads->changed($run->space_id, $run->conversation_id, activity: false);
        }

        return $created;
    }

    /**
     * Distinct entries this run already changed, created or proposed changes to.
     *
     * @return list<string> "type:id"
     */
    public function touchedTargets(AiRun $run): array
    {
        return AiChangesetItem::query()
            ->whereHas('changeset', fn ($query) => $query->where('run_id', $run->id))
            ->get(['id', 'target_type', 'target_id'])
            // A new entry not written yet counts as an entry of its own.
            ->map(fn (AiChangesetItem $item) => "{$item->target_type}:".($item->target_id ?? "new-{$item->id}"))
            ->unique()
            ->values()
            ->all();
    }

    private function title(AiRun $run): string
    {
        return Str::limit((string) (($run->setup['title'] ?? '') ?: $run->skill ?: $run->surface), 180);
    }
}
