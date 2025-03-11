<?php

namespace App\Http\Resources\Api;

use App\Models\Management\Space;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Space
 */
class SpaceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getRouteKey(),
            'name' => $this->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
