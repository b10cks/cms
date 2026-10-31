<?php

namespace App\Services\Team\Attention\Checks;

use App\Models\Management\Invite;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\Attention\AttentionCheck;
use App\Services\Team\Attention\AttentionItem;
use App\Services\Team\TeamScope;

/** Invites in the subtree that have waited for more than a week. */
class StaleInvites implements AttentionCheck
{
    public const DAYS = 7;

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function collect(TeamScope $scope, User $actor): array
    {
        if (! $this->authorization->canInTeam($actor, $scope->team, 'team.invites.view')) {
            return [];
        }

        $emails = Invite::query()
            ->pending()
            ->where('created_at', '<', now()->subDays(self::DAYS))
            ->where(fn ($query) => $query
                ->whereIn('team_id', $scope->teamIds)
                ->orWhereIn('space_id', $scope->spaceIds))
            ->orderBy('created_at')
            ->pluck('email');

        if ($emails->isEmpty()) {
            return [];
        }

        return [new AttentionItem(
            type: 'stale_invites',
            count: $emails->count(),
            details: $emails->take(5)->all(),
            target: [
                'name' => 'team-people',
                'params' => ['team' => $scope->team->id],
                'query' => ['segment' => 'pending'],
            ],
        )];
    }
}
