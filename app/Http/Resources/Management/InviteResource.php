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
            'team_id' => $this->team_id,
            'invitee_id' => $this->invitee_id,
            'invited_by' => $this->invited_by,
            'email' => $this->email,
            'role' => $this->role,
            'message' => $this->message,
            'status' => $this->getStatus(),
            'inviter' => $this->whenLoaded('inviter', fn () => new SimpleUserResource($this->inviter)),
            'invitee' => $this->whenLoaded('invitee', fn () => new SimpleUserResource($this->invitee)),
            'space' => $this->whenLoaded('space', fn () => new SpaceResource($this->space)),
            'team' => $this->whenLoaded('team', fn () => new TeamResource($this->team)),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function getStatus(): string
    {
        if ($this->isAccepted()) {
            return 'accepted';
        }

        if ($this->isDeclined()) {
            return 'declined';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'pending';
    }
}
