<?php

namespace App\Services\Auth;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MembershipService
{
    public function __construct(
        private readonly RoleService $roleService,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function assignTeamRole(Team $team, User|string $user, string $roleKey): void
    {
        $role = $this->roleService->resolveTeamRole($roleKey);
        $userId = $user instanceof User ? $user->id : $user;

        $this->upsertPivot(
            $team->users(),
            $userId,
            ['role_id' => $role->id],
        );

        $this->authorizationService->invalidateUser($userId);
    }

    public function assignSpaceRole(Space $space, User|string $user, string $roleKey): void
    {
        $role = $this->roleService->resolveSpaceRole($roleKey, $space->team);
        $userId = $user instanceof User ? $user->id : $user;

        $this->upsertPivot(
            $space->users(),
            $userId,
            ['role_id' => $role->id],
        );

        $this->authorizationService->invalidateUser($userId);
    }

    public function removeTeamMembership(Team $team, User|string $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        $team->users()->detach($userId);
        $this->authorizationService->invalidateUser($userId);
    }

    public function removeSpaceMembership(Space $space, User|string $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        $space->users()->detach($userId);
        $this->authorizationService->invalidateUser($userId);
    }

    private function upsertPivot(BelongsToMany $relation, string $userId, array $attributes): void
    {
        if (! $relation->wherePivot('user_id', $userId)->exists()) {
            $relation->attach($userId, $attributes + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $relation->updateExistingPivot($userId, $attributes + [
            'updated_at' => now(),
        ]);
    }
}
