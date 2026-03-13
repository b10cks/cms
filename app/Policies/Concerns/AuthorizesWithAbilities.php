<?php

namespace App\Policies\Concerns;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\AuthorizationService;

trait AuthorizesWithAbilities
{
    protected function authorization(): AuthorizationService
    {
        return app(AuthorizationService::class);
    }

    protected function canInSpace(User $user, Space $space, string $ability): bool
    {
        return $this->authorization()->canInSpace($user, $space, $ability);
    }

    protected function canInTeam(User $user, Team $team, string $ability): bool
    {
        return $this->authorization()->canInTeam($user, $team, $ability);
    }

    protected function hasAnyTeamRole(User $user, Team $team, array $roleKeys): bool
    {
        return array_intersect(
            $this->authorization()->teamRoleKeysForTeam($user, $team),
            $roleKeys,
        ) !== [];
    }

    protected function hasAnyInheritedTeamRoleForSpace(User $user, Space $space, array $roleKeys): bool
    {
        return array_intersect(
            $this->authorization()->teamRoleKeysForSpace($user, $space),
            $roleKeys,
        ) !== [];
    }
}
