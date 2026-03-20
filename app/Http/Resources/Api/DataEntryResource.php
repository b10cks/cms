<?php

namespace App\Http\Resources\Api;

use App\Models\Space\DataEntry;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataEntry
 *
 * @resourceProperty id format=uuid Unique identifier of the data entry.
 * @resourceProperty key Stable key of the data entry within the data source.
 * @resourceProperty value Resolved value of the entry. When the optional `dimension` query parameter is used, this returns the dimension-specific value if available, otherwise the base value.
 * @resourceProperty updated_at format=date-time Last update timestamp in ISO 8601 format.
 */
class DataEntryResource extends JsonResource
{
    public function toArray($request): array
    {
        $dimension = $request->get('dimension', null);

        return [
            'id' => $this->getRouteKey(),
            'key' => $this->key,
            'value' => $dimension ? data_get($this->dimensions, $dimension, $this->value) : $this->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
