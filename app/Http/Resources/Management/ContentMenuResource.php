<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
class ContentMenuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pid' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'block_id' => $this->block_id,
            'type' => $this->whenLoaded('block', fn() => $this->block->type),
            'children' => $this->whenCounted('children', fn() => $this->children_count > 0),
            'icon' => $this->whenLoaded('block', fn() => $this->block->icon),
            'color' => $this->whenLoaded('block', fn() => $this->block->color),
            'i18n' => $this->whenLoaded('i18n_children', fn() => ContentTranslationResource::collection($this->i18n_children)),
            'pat' => $this->published_at?->toIso8601String(),
            'uat' => $this->updated_at?->toIso8601String()
        ];
    }
}
