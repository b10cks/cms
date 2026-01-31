<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class TeamMemberListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'initials' => $this->initials,
            'role' => $this->whenPivotLoaded('team_user', fn() => $this->pivot->role),
            'is_active' => $this->deleted_at === null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
