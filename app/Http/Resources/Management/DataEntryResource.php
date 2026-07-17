<?php

namespace App\Http\Resources\Management;

use App\Models\Space\DataEntry;
use App\Services\Space\ShapeValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataEntry
 */
class DataEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shape = $this->dataSource?->shape;

        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'key' => $this->key,
            'value' => ShapeValue::decode($this->value, $shape),
            'dimensions' => empty($shape) ? $this->dimensions : collect($this->dimensions ?? [])
                ->map(fn ($value) => ShapeValue::decode($value, $shape))
                ->all(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
