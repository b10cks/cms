<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentTeamId = $request->route('team')?->id;

        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'level' => $this->level,
            'is_system' => $this->is_system,
            'team_id' => $this->team_id,
            'abilities' => $this->abilities,
            'is_read_only' => $this->is_system || ($currentTeamId && $this->team_id !== $currentTeamId),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
