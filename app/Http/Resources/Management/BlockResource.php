<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Block;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Block
 */
class BlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'type' => $this->type,
            'preview_template' => $this->preview_template,
            'schema' => $this->schema->toArray(),
            'editor' => $this->editor,
            'tags' => $this->tags,
            'templates_count' => $this->whenCounted('templates'),
            'folder' => $this->whenLoaded('folder', fn() => new BlockFolderResource($this->folder)),
            'folder_id' => $this->folder_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
