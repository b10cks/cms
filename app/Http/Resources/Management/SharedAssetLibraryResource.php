<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SharedAssetLibraryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'is_default' => $this->is_default,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'team' => new TeamResource($this->whenLoaded('team')),
            'shared_assets_count' => $this->whenCounted('sharedAssets'),
            'shared_assets' => SharedAssetResource::collection($this->whenLoaded('sharedAssets')),
            'permissions' => SharedAssetPermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
