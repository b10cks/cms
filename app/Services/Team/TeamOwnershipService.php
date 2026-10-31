<?php

namespace App\Services\Team;

use App\Enums\RoleScope;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who owns which team. A suspended owner keeps the membership but does not
 * count: a team whose direct owners are all suspended has "No owner".
 */
class TeamOwnershipService
{
    /** @return array<int, string> Ids of teams with at least one active direct owner. */
    public function ownedTeamIds(): array
    {
        return $this->directOwners()
            ->whereNull('users.suspended_at')
            ->distinct()
            ->pluck('team_user.team_id')
            ->all();
    }

    /** @return array<int, string> Ids of teams the user directly owns. */
    public function teamIdsOwnedBy(User $user): array
    {
        return $this->directOwners()
            ->where('team_user.user_id', $user->id)
            ->pluck('team_user.team_id')
            ->all();
    }

    /**
     * Teams the user owns where nobody else is an active direct owner, so
     * suspending the user leaves them without an owner.
     *
     * @return array<int, string>
     */
    public function soleOwnedTeamIds(User $user): array
    {
        $teamIds = $this->teamIdsOwnedBy($user);
        if ($teamIds === []) {
            return [];
        }

        $coOwned = $this->directOwners()
            ->whereIn('team_user.team_id', $teamIds)
            ->where('team_user.user_id', '!=', $user->id)
            ->whereNull('users.suspended_at')
            ->pluck('team_user.team_id')
            ->all();

        return array_values(array_diff($teamIds, $coOwned));
    }

    private function directOwners(): Builder
    {
        return DB::table('team_user')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->where('roles.scope', RoleScope::TEAM->value)
            ->where('roles.key', 'owner')
            ->whereNull('users.deleted_at')
            ->whereNull('teams.deleted_at');
    }
}
