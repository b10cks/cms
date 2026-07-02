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
            // This endpoint is unauthenticated (anyone with the invite id), so
            // expose only the minimum needed to render the accept screen — never
            // the full space/team objects, which carry settings and billing data.
            'space' => $this->whenLoaded('space', fn() => [
                'name' => $this->space->name,
                'slug' => $this->space->slug,
                'icon' => $this->space->icon_url,
            ]),
            'team' => $this->whenLoaded('team', fn() => [
                'name' => $this->team->name,
            ]),
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
