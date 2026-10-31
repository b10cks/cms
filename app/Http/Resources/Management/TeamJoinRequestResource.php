<?php

namespace App\Http\Resources\Management;

use App\Models\Management\TeamJoinRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TeamJoinRequest */
class TeamJoinRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'status' => $this->status->value,
            'user' => new SimpleUserResource($this->whenLoaded('user')),
            'domain' => $this->whenLoaded('domain', fn () => $this->domain?->domain),
            'default_role' => $this->whenLoaded('domain', fn () => $this->domain?->defaultRoleKey() ?? 'member'),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
