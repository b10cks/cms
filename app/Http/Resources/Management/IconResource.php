<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Icon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Icon
 */
class IconResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'body' => $this->body,
            'width' => $this->width,
            'height' => $this->height,
            'tags' => $this->tags ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
