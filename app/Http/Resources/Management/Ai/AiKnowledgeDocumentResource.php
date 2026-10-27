<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiKnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiKnowledgeDocument */
class AiKnowledgeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'slug' => $this->slug,
            'title' => $this->title,
            'type' => $this->type->value,
            'enabled' => $this->enabled,
            // Lists select the row without its draft to stay small.
            'draft' => $this->when(\array_key_exists('draft', $this->resource->getAttributes()), fn () => $this->draft),
            'source_name' => $this->source_name,
            'extraction_status' => $this->extraction_status,
            'published_revision' => $this->whenLoaded('publishedRevision', fn () => $this->publishedRevision ? [
                'id' => $this->publishedRevision->id,
                'revision' => $this->publishedRevision->revision,
                'published_at' => $this->publishedRevision->created_at?->toIso8601String(),
            ] : null),
            'has_unpublished_changes' => $this->when(
                $this->relationLoaded('publishedRevision') && \array_key_exists('draft', $this->resource->getAttributes()),
                fn () => $this->publishedRevision?->markdown !== $this->draft,
            ),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
