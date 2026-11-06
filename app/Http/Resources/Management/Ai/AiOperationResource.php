<?php

namespace App\Http\Resources\Management\Ai;

use App\Enums\AiOperationItemStatus;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Services\Ai\Operations\OperationPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An operation with its frozen counts, plan, budget and live progress.
 * `progress.by_status` always lists every status; `owed` is work not done
 * yet (pending or running), `reasons` breaks skipped and failed down by code.
 * `changeset` is the shared changeset's summary without items, so an
 * operation without a planning turn can show its review.
 *
 * @mixin AiOperation
 *
 * @resourceProperty kind shape=enum:translation|assets|general
 * @resourceProperty state shape=enum:draft|running|paused|completed|cancelled Completed means no entry is still queued. Read `counts.coverage.complete` for fulfillment.
 * @resourceProperty pause_reason shape=enum:budget|user|access_revoked|batch_failed|batch_cancelled?
 * @resourceProperty pause_requested type=boolean
 * @resourceProperty error shape=string?
 * @resourceProperty skill shape=string?
 * @resourceProperty conversation_id shape=string?
 * @resourceProperty schedule_id shape=string?
 * @resourceProperty scope shape=@AiOperationScope
 * @resourceProperty counts shape=@AiOperationCounts
 * @resourceProperty plan shape=@AiOperationPlan?
 * @resourceProperty plan_hash shape=string? Send back as `plan_hash` when approving.
 * @resourceProperty plan_run_id shape=string?
 * @resourceProperty approved_plan shape=@AiOperationPlan?
 * @resourceProperty approved_plan_hash shape=string?
 * @resourceProperty policy_snapshot_id shape=string? The policy pinned at approval. Newer knowledge does not reach an approved operation.
 * @resourceProperty budget shape=@AiOperationBudget
 * @resourceProperty usage shape=@AiOperationUsage
 * @resourceProperty progress shape=@AiOperationProgress
 * @resourceProperty current_run shape=@AiOperationCurrentRun?
 * @resourceProperty changeset_id shape=string?
 * @resourceProperty changeset shape=@AiChangesetResource?
 * @resourceProperty finished_at shape=date-time?
 */
class AiOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $byStatus = $this->statusCounts();
        $reasons = AiOperationItem::query()
            ->where('operation_id', $this->id)
            ->whereIn('status', [AiOperationItemStatus::Skipped->value, AiOperationItemStatus::Failed->value])
            ->toBase()
            ->selectRaw('status, reason, count(*) as total')
            ->groupBy('status', 'reason')
            ->get()
            ->groupBy('status')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->reason ?? 'unknown' => (int) $row->total]))
            ->all();

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'state' => $this->state->value,
            'pause_reason' => $this->pause_reason?->value,
            'pause_requested' => $this->pause_requested,
            'error' => $this->error,
            'skill' => $this->skill,
            'surface' => $this->surface,
            'prompt' => $this->prompt,
            'conversation_id' => $this->conversation_id,
            'schedule_id' => $this->schedule_id,
            'scope' => $this->scope,
            'counts' => $this->counts,
            'plan' => $this->plan,
            'plan_hash' => $this->plan !== null ? OperationPlan::hash($this->plan) : null,
            'plan_run_id' => $this->plan_run_id,
            'approved_plan' => $this->approved_plan,
            'approved_plan_hash' => $this->approved_plan_hash,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'policy_snapshot_id' => $this->policy_snapshot_id,
            'budget' => [
                'max_usd' => $this->max_usd,
                'spent_usd' => $this->spent_usd,
                'remaining_usd' => $this->remainingUsd(),
            ],
            'usage' => ['input_tokens' => $this->input_tokens, 'output_tokens' => $this->output_tokens],
            'progress' => [
                'by_status' => $byStatus,
                'owed' => $byStatus[AiOperationItemStatus::Pending->value] + $byStatus[AiOperationItemStatus::Running->value],
                'reasons' => (object) $reasons,
                'batches' => $this->batch_count,
            ],
            'current_run' => $this->currentRun ? ['id' => $this->currentRun->id, 'state' => $this->currentRun->state->value] : null,
            'changeset_id' => $this->changeset?->id,
            'changeset' => $this->changeset ? new AiChangesetResource($this->changeset) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
