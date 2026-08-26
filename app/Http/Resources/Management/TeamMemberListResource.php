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
            'user' => [
                'id' => $this->id,
                'firstname' => $this->firstname,
                'lastname' => $this->lastname,
                'name' => $this->name,
                'email' => $this->email,
                'avatar' => $this->avatar_url,
                'initials' => $this->initials,
            ],
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'initials' => $this->initials,
            'role' => $this->role_key ?? null,
            'membership_origin' => $this->membership_origin ?? 'team',
            'inherited_from' => $this->inherited_from ?? null,
            'can_assign_team_role' => (bool) ($this->can_assign_team_role ?? true),
            'can_remove' => (bool) ($this->can_remove ?? true),
            'space_memberships' => $this->space_memberships ?? [],
            'is_active' => $this->deleted_at === null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'joined_at' => $this->joined_at ? \Illuminate\Support\Carbon::parse($this->joined_at)->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
