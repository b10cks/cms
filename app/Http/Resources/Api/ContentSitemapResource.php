<?php

namespace App\Http\Resources\Api;

use App\Models\Space\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @resourceProperty id format=uuid Unique content identifier.
 * @resourceProperty name Human-readable content name.
 * @resourceProperty full_slug Full resolved path of the sitemap row.
 * @resourceProperty meta type=object Sitemap-ready meta object containing normalized robots and canonical values.
 * @resourceProperty published_at format=date-time Publication timestamp in ISO 8601 format.
 */
class ContentSitemapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'],
            'full_slug' => $this['full_slug'],
            'language_iso' => $this['language_iso'],
            'meta' => $this['meta'],
            'published_at' => $this['published_at'],
        ];
    }
}
