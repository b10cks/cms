<?php

namespace App\Http\Resources\Api;

use App\Models\Space\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 *
 * @resourceProperty id format=uuid Unique content identifier.
 * @resourceProperty name Human-readable content name.
 * @resourceProperty slug URL-safe content slug.
 * @resourceProperty full_slug Full resolved slug including parent path segments.
 * @resourceProperty position 0-based sort position within the parent.
 * @resourceProperty language_iso ISO language code of the content entry.
 * @resourceProperty published_at format=date-time Publication timestamp in ISO 8601 format.
 * @resourceProperty created_at format=date-time Creation timestamp in ISO 8601 format.
 * @resourceProperty updated_at format=date-time Last update timestamp in ISO 8601 format.
 */
class SimpleContentResource extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->getRouteKey(),
            'name' => $this->name,
            'slug' => $this->slug,
            'full_slug' => $this->full_slug,
            'position' => $this->position,
            'language_iso' => $this->language_iso,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
