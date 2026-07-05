<?php

namespace App\Http\Resources\Management;

use App\Models\Space\AssetCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetCollection
 */
class AssetCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assetsCount = $this->resource->getAttributes()['assets_count'] ?? null;

        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'type' => $this->type,
            'rules' => $this->rules,
            'settings' => $this->settings,
            'cover_asset_id' => $this->cover_asset_id,
            'cover_asset' => $this->whenLoaded('coverAsset', fn () => new AssetResource($this->coverAsset)),
            'assets_count' => $assetsCount !== null ? (int) $assetsCount : null,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
