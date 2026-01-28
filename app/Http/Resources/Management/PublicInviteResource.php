<?php

namespace App\Http\Resources\Management;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicInviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space' => $this->whenLoaded('space', fn() => new SpaceResource($this->space)),
            'team' => $this->whenLoaded('team', fn() => new TeamResource($this->team)),
            'inviter' => $this->whenLoaded('inviter', fn() => new SimpleUserResource($this->inviter)),
            'email_hash' => hash('sha256', $this->email),
            'role' => $this->role,
            'message' => $this->message,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->getStatus(),
        ];
    }

    private function getStatus(): string
    {
        if ($this->isAccepted()) {
            return 'accepted';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'pending';
    }
}
