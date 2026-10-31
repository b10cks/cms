<?php

namespace App\Services\Team\Attention\Checks;

use App\Enums\JoinRequestStatus;
use App\Models\Management\TeamJoinRequest;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\Attention\AttentionCheck;
use App\Services\Team\Attention\AttentionItem;
use App\Services\Team\TeamScope;

/**
 * People who asked to join a team in the subtree through a verified domain.
 * Requests are decided on the Security page, which needs domain management.
 */
class PendingJoinRequests implements AttentionCheck
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function collect(TeamScope $scope, User $actor): array
    {
        if (! $this->authorization->canInTeam($actor, $scope->team, 'team.domains.manage')) {
            return [];
        }

        $requests = TeamJoinRequest::query()
            ->with('user:id,email')
            ->whereIn('team_id', $scope->teamIds)
            ->where('status', JoinRequestStatus::Pending)
            ->latest()
            ->get();

        if ($requests->isEmpty()) {
            return [];
        }

        return [new AttentionItem(
            type: 'join_requests',
            count: $requests->count(),
            details: $requests->take(3)->map(fn (TeamJoinRequest $request) => (string) $request->user?->email)->filter()->values()->all(),
            target: ['name' => 'team-saml', 'params' => ['team' => $scope->team->id], 'hash' => '#join-requests'],
        )];
    }
}
