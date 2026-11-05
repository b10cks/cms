<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiChangeset;
use App\Services\Ai\Translation\TranslationLineage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiChangeset
 *
 * @resourceProperty status shape=enum:open|partially_applied|applied|discarded
 * @resourceProperty inline type=boolean
 * @resourceProperty operation_id shape=string?
 * @resourceProperty item_count type=integer
 * @resourceProperty pending_count type=integer
 * @resourceProperty run shape=@AiChangesetRunSummary On a single changeset.
 * @resourceProperty actor shape=@AiActor On a single changeset.
 * @resourceProperty items shape=@AiChangesetItemResource[] On a single changeset. Items of target types the user may view.
 */
class AiChangesetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'inline' => $this->inline,
            // Set when the changeset collects an operation's batches; its review is grouped on the operation endpoints.
            'operation_id' => $this->operation_id,
            'item_count' => $this->item_count,
            'pending_count' => $this->pending_count,
            'run' => $this->whenLoaded('run', fn () => $this->run ? [
                'id' => $this->run->id,
                'state' => $this->run->state->value,
                'partial' => $this->run->partial,
                'stopped_by' => $this->run->setup['stopped_by'] ?? null,
                'cost_usd' => $this->run->cost_usd,
            ] : null),
            // Users live in the management database; a deleted one leaves the ID behind.
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => trim("{$this->actor->firstname} {$this->actor->lastname}"),
            ] : null),
            'items' => $this->whenLoaded('items', function () {
                $lineage = app(TranslationLineage::class)->forItems($this->items->pluck('id')->all());

                return $this->items->map(fn ($item) => new AiChangesetItemResource($item, $lineage[$item->id] ?? []));
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
