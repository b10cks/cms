<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Space
 */
class SpaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon_url,
            'color' => $this->color,
            'description' => $this->description,
            'settings' => $this->settings->toArray(),
            'team_id' => $this->team_id,
            'user_count' => $this->whenCounted('users', fn() => $this->users_count),
            'content_updated_at' => $this->content_updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
