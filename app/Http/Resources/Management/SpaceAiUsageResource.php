<?php

namespace App\Http\Resources\Management;

use App\Models\Management\SpaceAiUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpaceAiUsage */
class SpaceAiUsageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'max_tokens' => $this->max_tokens,
            'used_tokens' => $this->used_tokens,
            'valid_to' => $this->valid_to,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
