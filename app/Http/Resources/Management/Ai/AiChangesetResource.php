<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiChangeset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiChangeset */
class AiChangesetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'inline' => $this->inline,
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
            'items' => AiChangesetItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
