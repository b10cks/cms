<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Redirect;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Redirect
 */
class RedirectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getRouteKey(),
            'source' => $this->source,
            'target' => $this->target,
            'status_code' => $this->status_code,
            'hits' => $this->hits,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
