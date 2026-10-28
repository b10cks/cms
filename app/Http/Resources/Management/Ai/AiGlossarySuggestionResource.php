<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiGlossarySuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiGlossarySuggestion */
class AiGlossarySuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_locale' => $this->source_locale,
            'term' => $this->term,
            'translations' => (object) ($this->translations ?? []),
            'forbidden' => $this->forbidden ?? [],
            'note' => $this->note,
            'evidence' => $this->evidence,
            'status' => $this->status,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => trim("{$this->actor->firstname} {$this->actor->lastname}"),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
