<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'type' => $this->type,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', function() {
                return [
                    'id' => $this->parent->id,
                    'name' => $this->parent->name,
                ];
            }),
            'settings' => $this->settings,
            'user_count' => $this->whenCounted('users'),
            'spaces_count' => $this->whenCounted('spaces'),
            'children_count' => $this->whenCounted('children'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
