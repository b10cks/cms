<?php

namespace App\Http\Resources\Api;

use App\Models\Space\DataEntry;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataEntry
 */
class DataEntryResource extends JsonResource
{
    public function toArray($request): array
    {
        $dimension = $request->get('dimension', null);

        return [
            'id' => $this->getRouteKey(),
            'key' => $this->key,
            'value' =>  $dimension ? data_get($this->dimensions, $dimension, $this->value) : $this->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
