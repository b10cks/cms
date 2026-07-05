<?php

namespace App\Http\Resources\Management;

use App\Models\Space\AssetShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Management-facing share representation. Includes the full token so the
 * frontend can build the public URL
 * (`https://{app-host}/share/{spaceId}/{token}`).
 *
 * @mixin AssetShare
 */
class AssetShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'name' => $this->name,
            'description' => $this->description,
            'source_type' => $this->source_type,
            'collection_id' => $this->collection_id,
            'folder_id' => $this->folder_id,
            'asset_ids' => $this->asset_ids,
            'package_id' => $this->package_id,
            'package' => $this->whenLoaded('package', fn () => $this->package ? [
                'id' => $this->package->id,
                'state' => $this->package->state,
                'progress' => $this->package->progress,
                'file_size' => $this->package->file_size,
                'asset_count' => $this->package->asset_count,
                'is_stale' => $this->package->is_stale,
                'expires_at' => $this->package->expires_at?->toIso8601String(),
            ] : null),
            'has_password' => $this->hasPassword(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_limit' => $this->download_limit,
            'download_count' => $this->download_count,
            'view_count' => $this->view_count,
            'allow_individual_downloads' => $this->allow_individual_downloads,
            'settings' => $this->settings,
            'is_revoked' => $this->isRevoked(),
            'is_expired' => $this->isExpired(),
            'last_accessed_at' => $this->last_accessed_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'display_name' => $this->creator->display_name,
                'email' => $this->creator->email,
            ] : null),
        ];
    }
}
