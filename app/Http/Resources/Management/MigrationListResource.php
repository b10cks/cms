<?php

namespace App\Http\Resources\Management;

use App\Models\Management\SpaceMigration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpaceMigration
 */
class MigrationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_space_id' => $this->source_space_id,
            'target_space_id' => $this->target_space_id,
            'state' => $this->state,
            'progress' => $this->progress,
            'scope' => $this->scope,
            'conflict_strategy' => $this->conflict_strategy,
            'stats' => $this->stats,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'source_space' => $this->whenLoaded('sourceSpace', fn() => [
                'id' => $this->sourceSpace->id,
                'name' => $this->sourceSpace->name,
                'slug' => $this->sourceSpace->slug,
            ]),
            'target_space' => $this->whenLoaded('targetSpace', fn() => [
                'id' => $this->targetSpace->id,
                'name' => $this->targetSpace->name,
                'slug' => $this->targetSpace->slug,
            ]),
            'created_by' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'display_name' => $this->creator->display_name,
                'email' => $this->creator->email,
            ]),
        ];
    }
}
