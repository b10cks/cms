<?php

namespace App\Http\Resources\Management;

use App\Models\Management\SpaceBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpaceBackup
 */
class BackupDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'state' => $this->state,
            'progress' => $this->progress,
            'name' => $this->name,
            'description' => $this->description,
            'recipients' => $this->recipients,
            'has_password' => !empty($this->password),
            's3_path' => $this->s3_path,
            'file_size' => $this->file_size,
            'checksum' => $this->checksum,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'display_name' => $this->creator->display_name,
                'email' => $this->creator->email,
            ]),
        ];
    }
}
