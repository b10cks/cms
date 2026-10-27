<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiGlossary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiGlossary */
class AiGlossaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'terms_count' => $this->whenCounted('terms'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
