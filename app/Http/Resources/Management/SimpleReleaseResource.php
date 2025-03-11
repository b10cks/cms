<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Release;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Release
 */
class SimpleReleaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String()
        ];
    }
}
