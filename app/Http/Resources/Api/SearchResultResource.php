<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @resourceProperty id Search result identifier.
 * @resourceProperty name Human-readable content name.
 * @resourceProperty slug URL slug of the matched content.
 * @resourceProperty full_slug Full resolved path of the matched content.
 * @resourceProperty language_iso example=en ISO language code of the matched content.
 * @resourceProperty block_id Identifier of the related block.
 * @resourceProperty published_at format=date-time Publication timestamp in ISO 8601 format.
 * @resourceProperty relevance_score example=0.9876 Search relevance score rounded to 4 decimal places.
 */
class SearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'slug' => $this->resource['slug'],
            'full_slug' => $this->resource['full_slug'],
            'language_iso' => $this->resource['language_iso'],
            'block_id' => $this->resource['block_id'],
            'published_at' => $this->resource['published_at'],
            'relevance_score' => round($this->resource['relevance_score'], 4),
        ];
    }
}
