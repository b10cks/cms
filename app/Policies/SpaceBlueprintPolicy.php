<?php

namespace App\Policies;

use App\Models\Management\SpaceBlueprint;
use App\Models\Management\Team;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class SpaceBlueprintPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    /** Roles that may read a team's blueprints. */
    private const VIEW_ROLES = ['member', 'admin', 'owner'];

    /** Roles that may create and edit a team's blueprints. */
    private const MANAGE_ROLES = ['admin', 'owner'];

    /**
     * A null team means the system-wide blueprints, which every authenticated
     * user may read — they are the starting points offered on space creation.
     */
    public function viewAny(User $user, ?Team $team = null): bool
    {
        return $team === null
            || $user->is_root
            || $this->hasAnyTeamRole($user, $team, self::VIEW_ROLES);
    }

    public function view(User $user, SpaceBlueprint $blueprint): bool
    {
        if ($blueprint->team_id === null) {
            return true;
        }

        return $this->hasBlueprintTeamRole($user, $blueprint, self::VIEW_ROLES);
    }

    /**
     * Only root may create a blueprint that belongs to no team: it becomes
     * visible to, and usable by, every user on the instance.
     */
    public function create(User $user, ?Team $team = null): bool
    {
        if ($team === null) {
            return $user->is_root;
        }

        return $user->is_root || $this->hasAnyTeamRole($user, $team, self::MANAGE_ROLES);
    }

    public function update(User $user, SpaceBlueprint $blueprint): bool
    {
        return $this->hasBlueprintTeamRole($user, $blueprint, self::MANAGE_ROLES);
    }

    public function delete(User $user, SpaceBlueprint $blueprint): bool
    {
        return $this->hasBlueprintTeamRole($user, $blueprint, ['owner']);
    }

    /**
     * Team roles are inherited downward, so a role on any ancestor of the
     * owning team counts. A blueprint without a team is root-only, as is one
     * whose team has been deleted.
     */
    private function hasBlueprintTeamRole(User $user, SpaceBlueprint $blueprint, array $roleKeys): bool
    {
        if ($user->is_root) {
            return true;
        }

        if ($blueprint->team_id === null) {
            return false;
        }

        $team = $blueprint->relationLoaded('team')
            ? $blueprint->team
            : Team::query()->find($blueprint->team_id);

        return $team !== null && $this->hasAnyTeamRole($user, $team, $roleKeys);
    }
}
