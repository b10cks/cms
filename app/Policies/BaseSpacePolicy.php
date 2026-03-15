<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Space\SpaceUserPolicyService;
use Illuminate\Auth\Access\HandlesAuthorization;

abstract class BaseSpacePolicy
{
    use HandlesAuthorization;

    public function __construct(protected SpaceUserPolicyService $spaceUserPolicyService)
    {
    }

    public function hasRoles(User $user, Space $space, string|array $roles): bool
    {
        $spaceUserPivot = $this->spaceUserPolicyService->getSpaceUserPivot($user, $space);
        $roles = \is_array($roles) ? $roles : [$roles];

        return $spaceUserPivot && \in_array($spaceUserPivot['role'], $roles);
    }
}
