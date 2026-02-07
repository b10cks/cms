<?php

namespace App\Policies;

use App\Models\Management\SpaceBlueprint;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\DB;

class SpaceBlueprintPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Team $team): bool
    {
        return $user->is_root || $this->userHasTeamRole($user, $team, ['member', 'admin', 'owner']);
    }

    public function view(User $user, SpaceBlueprint $blueprint, Team $team): bool
    {
        if ($blueprint->team_id !== $team->id) {
            return false;
        }

        return $user->is_root || $this->userHasTeamRole($user, $team, ['member', 'admin', 'owner']);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->is_root || $this->userHasTeamRole($user, $team, ['admin', 'owner']);
    }

    public function update(User $user, SpaceBlueprint $blueprint, Team $team): bool
    {
        if ($blueprint->team_id !== $team->id) {
            return false;
        }

        return $user->is_root || $this->userHasTeamRole($user, $team, ['admin', 'owner']);
    }

    public function delete(User $user, SpaceBlueprint $blueprint, Team $team): bool
    {
        if ($blueprint->team_id !== $team->id) {
            return false;
        }

        return $user->is_root || $this->userHasTeamRole($user, $team, ['owner']);
    }

    private function userHasTeamRole(User $user, Team $team, array $roleKeys): bool
    {
        return DB::table('team_user')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->where('team_user.user_id', $user->id)
            ->where('team_user.team_id', $team->id)
            ->whereIn('roles.key', $roleKeys)
            ->exists();
    }
}
