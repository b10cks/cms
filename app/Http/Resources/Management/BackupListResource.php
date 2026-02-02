<?php

namespace App\Http\Resources\Management;

use App\Models\Management\SpaceBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpaceBackup
 */
class BackupListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'state' => $this->state,
            'progress' => $this->progress,
            'name' => $this->name,
            'recipients' => $this->recipients,
            'description' => $this->description,
            'file_size' => $this->file_size,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_by' => new SimpleUserResource($this->whenLoaded('creator')),
        ];
    }
}
