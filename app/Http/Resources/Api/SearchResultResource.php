<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
