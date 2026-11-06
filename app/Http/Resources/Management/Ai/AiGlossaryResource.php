<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiGlossary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiGlossary
 *
 * @resourceProperty enabled shape=boolean
 * @resourceProperty terms_count shape=integer Rows, one per meaning.
 * @resourceProperty owner_name shape=string? The space or team it belongs to, on a list.
 */
class AiGlossaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'owner_id' => $this->space_id ?? $this->team_id,
            'owner_name' => $this->when($this->relationLoaded('team') || $this->relationLoaded('space'), fn () => $this->space->name ?? $this->team->name ?? null),
            'name' => $this->name,
            'enabled' => $this->enabled,
            'terms_count' => $this->whenCounted('concepts'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
