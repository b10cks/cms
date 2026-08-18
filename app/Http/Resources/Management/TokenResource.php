<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Token;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Token
 */
class TokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Listed so Settings can copy an existing delivery token. Deliberate.
            'token' => $this->token,
            'abilities' => $this->abilities->toArray(),
            'execution_count' => $this->execution_count,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
