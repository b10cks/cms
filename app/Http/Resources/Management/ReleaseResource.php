<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Release
 */
class ReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'description' => $this->description,
            'settings' => $this->settings ?? new \StdClass(),
            'owner_id' => $this->owner_id,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'committed_at' => $this->committed_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'versions_count' => $this->whenCounted('versions', fn() => $this->versions_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
