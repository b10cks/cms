<?php

namespace App\Actions\Team;

use App\Enums\JoinRequestStatus;
use App\Models\Management\Team;
use App\Models\Management\TeamJoinRequest;
use App\Models\User;
use App\Services\Team\TeamDomainService;
use Illuminate\Validation\ValidationException;

/**
 * The user asks to join a team whose verified domain matches their verified
 * email. Sending twice is a no-op; a declined request stays declined.
 */
class RequestToJoinTeam
{
    public function __construct(private readonly TeamDomainService $domains) {}

    public function execute(User $user, Team $team): TeamJoinRequest
    {
        $domain = $this->domains->joinableDomains($user)->firstWhere('team_id', $team->id);

        if (! $domain) {
            throw ValidationException::withMessages([
                'team_id' => [__('You cannot request to join this team.')],
            ]);
        }

        /** @var TeamJoinRequest|null $existing */
        $existing = $domain->getRelation('joinRequest');

        if ($existing?->status === JoinRequestStatus::Pending) {
            return $existing;
        }

        if ($existing?->status === JoinRequestStatus::Declined) {
            throw ValidationException::withMessages([
                'team_id' => [__('The team declined your request. Ask an owner for an invite.')],
            ]);
        }

        // An approved request whose membership has since ended starts over.
        $joinRequest = $existing ?? new TeamJoinRequest(['team_id' => $team->id, 'user_id' => $user->id]);
        $joinRequest->forceFill([
            'team_domain_id' => $domain->id,
            'status' => JoinRequestStatus::Pending,
            'decided_by' => null,
            'decided_at' => null,
        ])->save();

        return $joinRequest;
    }
}
