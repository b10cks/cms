<?php

namespace App\Services\Auth;

use App\Enums\RoleScope;
use App\Models\Management\Role;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Guards membership mutations against privilege escalation and orphaned
 * resources. Levels come from the role catalog (100–300 for system roles);
 * an actor may never grant a role above their own effective level, nor
 * modify a member who outranks them.
 */
class MembershipGuard
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly RoleService $roleService,
    ) {}

    public function effectiveTeamLevel(User $actor, Team $team): int
    {
        if ($actor->is_root) {
            return PHP_INT_MAX;
        }

        $levels = array_map(
            fn (string $key) => (int) config("authorization.roles.team.{$key}.level", 0),
            $this->authorizationService->teamRoleKeysForTeam($actor, $team),
        );

        return $levels === [] ? 0 : max($levels);
    }

    public function effectiveSpaceLevel(User $actor, Space $space): int
    {
        if ($actor->is_root) {
            return PHP_INT_MAX;
        }

        $levels = array_map(
            fn (string $key) => (int) config("authorization.roles.team.{$key}.level", 0),
            $this->authorizationService->teamRoleKeysForSpace($actor, $space),
        );

        $spaceRoleKey = $this->authorizationService->spaceRoleKeyForSpace($actor, $space);
        if ($spaceRoleKey !== null) {
            try {
                $levels[] = $this->roleService->resolveSpaceRole($spaceRoleKey, $space->team)->level;
            } catch (ValidationException) {
                // Stale role key in the cached graph — ignore.
            }
        }

        return $levels === [] ? 0 : max($levels);
    }

    public function ensureCanAssignTeamRole(User $actor, Team $team, Role|string $role): void
    {
        $role = $role instanceof Role ? $role : $this->roleService->resolveTeamRole($role);

        if ($role->level > $this->effectiveTeamLevel($actor, $team)) {
            $this->denyRole();
        }
    }

    public function ensureCanAssignSpaceRole(User $actor, Space $space, Role|string $role): void
    {
        $role = $role instanceof Role ? $role : $this->roleService->resolveSpaceRole($role, $space->team);

        if ($role->level > $this->effectiveSpaceLevel($actor, $space)) {
            $this->denyRole();
        }
    }

    public function ensureCanManageTeamMember(User $actor, Team $team, User|string $subject): void
    {
        $subjectLevel = $this->memberTeamRole($team, $subject)?->level ?? 0;

        if ($subjectLevel > $this->effectiveTeamLevel($actor, $team)) {
            $this->denyMember();
        }
    }

    public function ensureCanManageSpaceMember(User $actor, Space $space, User|string $subject): void
    {
        $subjectLevel = $this->memberSpaceRole($space, $subject)?->level ?? 0;

        if ($subjectLevel > $this->effectiveSpaceLevel($actor, $space)) {
            $this->denyMember();
        }
    }

    /**
     * A team must always keep at least one direct owner; otherwise nobody
     * short of root could administer it again. Spaces are exempt — their
     * owning team's owners retain full space management via inheritance.
     */
    public function ensureTeamRetainsOwner(Team $team, User|string $subject): void
    {
        $subjectId = $subject instanceof User ? $subject->id : $subject;

        $currentRole = $this->memberTeamRole($team, $subjectId);
        if (! $currentRole || $currentRole->key !== 'owner') {
            return;
        }

        $otherOwners = $team->users()
            ->wherePivotNotIn('user_id', [$subjectId])
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->where('roles.scope', RoleScope::TEAM->value)
            ->where('roles.key', 'owner')
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages([
                'role' => [__('This is the team\'s only owner. Assign another owner before removing or demoting them.')],
            ]);
        }
    }

    public function memberTeamRole(Team $team, User|string $subject): ?Role
    {
        $subjectId = $subject instanceof User ? $subject->id : $subject;
        $roleId = $team->users()->where('users.id', $subjectId)->first()?->pivot?->role_id;

        return $roleId ? Role::query()->find($roleId) : null;
    }

    public function memberSpaceRole(Space $space, User|string $subject): ?Role
    {
        $subjectId = $subject instanceof User ? $subject->id : $subject;
        $roleId = $space->users()->where('users.id', $subjectId)->first()?->pivot?->role_id;

        return $roleId ? Role::query()->find($roleId) : null;
    }

    private function denyRole(): never
    {
        throw ValidationException::withMessages([
            'role' => [__('You cannot assign a role above your own.')],
        ]);
    }

    private function denyMember(): never
    {
        throw ValidationException::withMessages([
            'user' => [__('You cannot modify a member with a higher role than your own.')],
        ]);
    }
}
