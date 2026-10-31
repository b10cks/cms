<?php

namespace App\Services\Team\Attention\Checks;

use App\Enums\RoleScope;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\Attention\AttentionCheck;
use App\Services\Team\Attention\AttentionItem;
use App\Services\Team\TeamScope;
use Illuminate\Support\Facades\DB;

/**
 * Teams in the subtree without a direct owner. Owners up the chain still
 * manage them, but MembershipGuard expects every team to keep one.
 */
class TeamsWithoutOwner implements AttentionCheck
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function collect(TeamScope $scope, User $actor): array
    {
        if (! $this->authorization->canInTeam($actor, $scope->team, 'team.members.view')) {
            return [];
        }

        $ownedTeamIds = DB::table('team_user')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->whereIn('team_user.team_id', $scope->teamIds)
            ->where('roles.scope', RoleScope::TEAM->value)
            ->where('roles.key', 'owner')
            ->distinct()
            ->pluck('team_user.team_id')
            ->all();

        $ownerless = DB::table('teams')
            ->whereIn('id', array_diff($scope->teamIds, $ownedTeamIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($ownerless->isEmpty()) {
            return [];
        }

        return [new AttentionItem(
            type: 'teams_without_owner',
            count: $ownerless->count(),
            details: $ownerless->take(5)->pluck('name')->all(),
            target: [
                'name' => 'team-people',
                'params' => ['team' => $ownerless->first()->id],
            ],
        )];
    }
}
