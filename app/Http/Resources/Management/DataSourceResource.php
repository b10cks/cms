<?php

namespace App\Http\Resources\Management;

use App\Models\Space\DataSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataSource
 */
class DataSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'dimensions' => $this->dimensions,
            'settings' => $this->settings,
            'shape' => $this->shape,
            'is_active' => $this->is_active,
            'entries_count' => $this->whenCounted('entries', fn() => $this->entries_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
