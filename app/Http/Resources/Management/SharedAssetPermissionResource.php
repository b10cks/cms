<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SharedAssetPermissionResource extends JsonResource
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
            'library_id' => $this->library_id,
            'shared_asset_id' => $this->shared_asset_id,
            'accessor_type' => $this->accessor_type,
            'accessor_id' => $this->accessor_id,
            'permission' => $this->permission,
            'inherited' => $this->inherited,
            'conditions' => $this->conditions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'library' => new SharedAssetLibraryResource($this->whenLoaded('library')),
            'shared_asset' => new SharedAssetResource($this->whenLoaded('sharedAsset')),
        ];
    }
}
