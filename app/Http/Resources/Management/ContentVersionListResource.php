<?php

namespace App\Http\Resources\Management;

use App\Models\Space\ContentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentVersion
 */
class ContentVersionListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'message' => $this->message,
            'parent_id' => $this->parent_id,
            'release' => $this->whenLoaded('release', fn () => new SimpleReleaseResource($this->release)),
            'author' => $this->whenLoaded('createdBy', fn () => new SimpleUserResource($this->createdBy)),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
