<?php

namespace App\Http\Resources\Management;

use App\Models\Space\BlockVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlockVersion
 */
class BlockVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'block_id' => $this->block_id,
            'parent_id' => $this->parent_id,
            'data' => $this->data,
            'commit_message' => $this->commit_message,
            'created_by' => $this->whenLoaded('createdBy', fn () => new SimpleUserResource($this->createdBy)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
