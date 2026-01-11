<?php

namespace App\Http\Resources\Management;

use App\Models\Space\AssetFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetFolder
 */
class AssetFolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getRouteKey(),
            'external_id' => $this->external_id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'children_count' => $this->whenCounted('children', fn() => $this->children_count),
            'assets_count' => $this->whenCounted('assets', fn() => $this->assets_count),
            'parent' => $this->whenLoaded('parent', fn() => new AssetFolderResource($this->parent)),
            'children' => $this->whenLoaded('children', fn() => AssetFolderResource::collection($this->children)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
