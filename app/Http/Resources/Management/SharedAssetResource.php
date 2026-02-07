<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SharedAssetResource extends JsonResource
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
            'source_space_id' => $this->source_space_id,
            'source_asset_id' => $this->source_asset_id,
            'shared_name' => $this->shared_name,
            'shared_description' => $this->shared_description,
            'shared_tags' => $this->shared_tags,
            'shared_metadata' => $this->shared_metadata,
            'access_count' => $this->access_count,
            'last_accessed_at' => $this->last_accessed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Computed fields
            'display_name' => $this->getDisplayName(),
            
            // Relationships
            'library' => new SharedAssetLibraryResource($this->whenLoaded('library')),
            'source_space' => new SpaceResource($this->whenLoaded('sourceSpace')),
            'permissions' => SharedAssetPermissionResource::collection($this->whenLoaded('permissions')),
            
            // Original asset details (if needed)
            'source_asset' => $this->when(
                $request->query('include_source_asset') === 'true',
                function () {
                    $asset = $this->getSourceAsset();
                    return $asset ? new AssetResource($asset) : null;
                }
            ),
        ];
    }
}
