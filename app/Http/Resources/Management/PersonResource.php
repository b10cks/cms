<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = $this->resource;

        return [
            'kind' => $person['kind'],
            'id' => $person['id'],
            'user_id' => $person['user_id'],
            'invite_id' => $person['invite_id'],
            'user' => $person['user'],
            'email' => $person['email'],
            'role' => $person['role'],
            'state' => $person['state'],
            'can_assign_role' => $person['can_assign_role'],
            'can_remove' => $person['can_remove'],
            'membership_origin' => $person['membership_origin'],
            'space_memberships' => $person['space_memberships'],
            'joined_at' => $person['joined_at'],
            'invited_at' => $person['invited_at'],
            'expires_at' => $person['expires_at'],
            'created_at' => $person['created_at'],
        ];
    }
}
