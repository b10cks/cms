<?php

namespace App\Http\Resources\Management;

use App\Models\Space\AssetPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetPackage
 */
class AssetPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'source_type' => $this->source_type,
            'collection_id' => $this->collection_id,
            'folder_id' => $this->folder_id,
            'asset_ids' => $this->asset_ids,
            'state' => $this->state,
            'progress' => $this->progress,
            'error' => $this->error,
            'file_size' => $this->file_size,
            'checksum' => $this->checksum,
            'asset_count' => $this->asset_count,
            'is_stale' => $this->is_stale,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'display_name' => $this->creator->display_name,
                'email' => $this->creator->email,
            ]),
        ];
    }
}
