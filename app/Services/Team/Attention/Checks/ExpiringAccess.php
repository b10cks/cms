<?php

namespace App\Services\Team\Attention\Checks;

use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\Attention\AttentionCheck;
use App\Services\Team\Attention\AttentionItem;
use App\Services\Team\TeamScope;
use Illuminate\Support\Facades\DB;

/** Team and space memberships in the subtree that end within two weeks. */
class ExpiringAccess implements AttentionCheck
{
    public const DAYS = 14;

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function collect(TeamScope $scope, User $actor): array
    {
        if (! $this->authorization->canInTeam($actor, $scope->team, 'team.members.view')) {
            return [];
        }

        $until = now()->addDays(self::DAYS);

        $teamRows = DB::table('team_user')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->whereIn('team_user.team_id', $scope->teamIds)
            ->whereNotNull('team_user.expires_at')
            ->where('team_user.expires_at', '<=', $until)
            ->select('users.firstname', 'users.lastname', 'users.email', 'teams.name as target', 'team_user.expires_at');

        $rows = DB::table('space_user')
            ->join('users', 'users.id', '=', 'space_user.user_id')
            ->join('spaces', 'spaces.id', '=', 'space_user.space_id')
            ->whereIn('space_user.space_id', $scope->spaceIds)
            ->whereNotNull('space_user.expires_at')
            ->where('space_user.expires_at', '<=', $until)
            ->select('users.firstname', 'users.lastname', 'users.email', 'spaces.name as target', 'space_user.expires_at')
            ->unionAll($teamRows)
            ->orderBy('expires_at')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return [new AttentionItem(
            type: 'expiring_access',
            count: $rows->count(),
            details: $rows->take(5)->map(function (object $row) {
                $name = trim("{$row->firstname} {$row->lastname}") ?: $row->email;

                return "{$name} ({$row->target})";
            })->all(),
            target: [
                'name' => 'team-people',
                'params' => ['team' => $scope->team->id],
                'query' => ['segment' => 'expiring'],
            ],
        )];
    }
}
