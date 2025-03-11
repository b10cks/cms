<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Invite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invite
 */
class InviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'invited_by' => $this->invited_by,
            'email' => $this->email,
            'role' => $this->role,
            'inviter' => $this->whenLoaded('inviter', fn () => new SimpleUserResource($this->inviter)),
            'space' => $this->whenLoaded('space', fn () => new SpaceResource($this->space)),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
