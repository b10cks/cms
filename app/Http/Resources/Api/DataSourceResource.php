<?php

namespace App\Http\Resources\Api;

use App\Models\Space\DataSource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataSource
 */
class DataSourceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getRouteKey(),
            'name' => $this->name,
            'slug' => $this->slug,
            'dimensions' => $this->dimensions,
            'shape' => $this->shape,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
