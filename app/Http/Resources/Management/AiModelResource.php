<?php

namespace App\Http\Resources\Management;

use App\Models\Management\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiModel */
class AiModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'model' => $this->model,
            'tags' => $this->tags ?? [],
            'token_multiplier' => $this->token_multiplier,
            'is_free' => $this->is_free,
            'is_active' => $this->is_active,
            'description' => $this->description,
            'provider' => $this->provider,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
