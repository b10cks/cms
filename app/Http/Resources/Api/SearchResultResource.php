<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/**
 * @resourceProperty id format=uuid Unique content identifier.
 * @resourceProperty name Human-readable content name.
 * @resourceProperty slug URL slug of the resolved content.
 * @resourceProperty block Slug of the assigned block definition.
 * @resourceProperty parent_id format=uuid Parent content identifier if this content is nested.
 * @resourceProperty full_slug Full resolved path including all parent slugs.
 * @resourceProperty content type=object additionalProperties=true Effective content payload after i18n, link, and asset resolution.
 * @resourceProperty language_iso Requested language ISO code used for content resolution.
 * @resourceProperty translations type=array itemResource=SimpleContentResource Published sibling translations of the resolved content.
 * @resourceProperty published_at format=date-time Publication timestamp in ISO 8601 format.
 * @resourceProperty first_published_at format=date-time First publication timestamp in ISO 8601 format.
 * @resourceProperty created_at format=date-time Creation timestamp in ISO 8601 format.
 * @resourceProperty updated_at format=date-time Last update timestamp in ISO 8601 format.
 * @resourceProperty relevance_score type=number example=0.9876 Search relevance score rounded to 4 decimal places.
 */
class SearchResultResource extends ContentResource
{
    public function __construct(mixed $resource, protected float $relevanceScore = 0.0)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'relevance_score' => round($this->relevanceScore ?: (float) data_get($this->resource, 'relevance_score', 0), 4),
        ];
    }
}
