<?php

namespace App\Policies;

use App\Models\Management\Team;
use App\Services\Team\TeamUserPolicyService;
use Illuminate\Auth\Access\HandlesAuthorization;
use App\Models\User;

abstract class BaseTeamPolicy
{
    use HandlesAuthorization;

    public function __construct(protected TeamUserPolicyService $teamUserPolicyService)
    {
    }

    public function hasRoles(User $user, Team $team, string|array $roles): bool
    {
        $teamUserPivot = $this->teamUserPolicyService->getTeamUserPivot($user, $team);
        $roles = \is_array($roles) ? $roles : [$roles];

        return $teamUserPivot && \in_array($teamUserPivot['role'], $roles);
    }

}
