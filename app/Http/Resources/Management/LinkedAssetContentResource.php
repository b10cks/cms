<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
class LinkedAssetContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'full_slug' => $this->full_slug,
            'language_iso' => $this->language_iso,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'block' => $this->block
                ? [
                    'id' => $this->block->id,
                    'name' => $this->block->name,
                    'color' => $this->block->color,
                    'icon' => $this->block->icon,
                    'slug' => $this->block->slug,
                ]
                : null,
            // Set by AssetLinkedContentController from AssetUsageService::linkedContents().
            'usage' => $this->resource->getAttribute('asset_usage'),
        ];
    }
}
