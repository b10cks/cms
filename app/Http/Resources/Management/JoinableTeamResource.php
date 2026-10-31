<?php

namespace App\Http\Resources\Management;

use App\Models\Management\TeamDomain;
use App\Models\Management\TeamJoinRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team the signed-in user may ask to join through their email domain,
 * with the state of their own request.
 *
 * @mixin TeamDomain
 */
class JoinableTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TeamJoinRequest|null $joinRequest */
        $joinRequest = $this->getRelation('joinRequest');

        return [
            'team' => [
                'id' => $this->team->id,
                'name' => $this->team->name,
                'avatar' => $this->team->avatar_url,
            ],
            'domain' => $this->domain,
            'request' => $joinRequest ? [
                'id' => $joinRequest->id,
                'status' => $joinRequest->status->value,
                'created_at' => $joinRequest->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
