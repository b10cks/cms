<?php

namespace App\Services\Auth;

use App\Enums\MembershipSource;
use App\Models\Management\Role;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MembershipService
{
    public function __construct(
        private readonly RoleService $roleService,
        private readonly AuthorizationService $authorizationService,
        private readonly MembershipGuard $guard,
        private readonly AuditService $auditService,
    ) {}

    /**
     * @param  MembershipSource|null  $source  How the membership came about.
     *                                         Recorded when the membership is
     *                                         created and never overwritten, so
     *                                         a later role change through
     *                                         another channel cannot launder an
     *                                         attached member into an invited
     *                                         one. See MembershipSource.
     */
    public function assignTeamRole(
        Team $team,
        User|string $user,
        string $roleKey,
        ?MembershipSource $source = null,
    ): void {
        $role = $this->roleService->resolveTeamRole($roleKey);
        $userId = $user instanceof User ? $user->id : $user;

        $previousRole = $this->guard->memberTeamRole($team, $userId);
        if ($previousRole?->key === 'owner' && $role->key !== 'owner') {
            $this->guard->ensureTeamRetainsOwner($team, $userId);
        }

        $this->upsertPivot(
            $team->users(),
            $userId,
            ['role_id' => $role->id],
            ['source' => $source?->value],
        );

        $this->authorizationService->invalidateUser($userId);
        $this->auditMembership($team, $userId, $previousRole, $role);
    }

    public function assignSpaceRole(Space $space, User|string $user, string $roleKey): void
    {
        $role = $this->roleService->resolveSpaceRole($roleKey, $space->team);
        $userId = $user instanceof User ? $user->id : $user;

        $previousRole = $this->guard->memberSpaceRole($space, $userId);

        $this->upsertPivot(
            $space->users(),
            $userId,
            ['role_id' => $role->id],
        );

        $this->authorizationService->invalidateUser($userId);
        $this->auditMembership($space, $userId, $previousRole, $role);
    }

    public function removeTeamMembership(Team $team, User|string $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $this->guard->ensureTeamRetainsOwner($team, $userId);

        $previousRole = $this->guard->memberTeamRole($team, $userId);
        if ($team->users()->detach($userId) > 0) {
            $this->auditMembership($team, $userId, $previousRole, null);
        }
        $this->authorizationService->invalidateUser($userId);
    }

    public function removeSpaceMembership(Space $space, User|string $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $previousRole = $this->guard->memberSpaceRole($space, $userId);
        if ($space->users()->detach($userId) > 0) {
            $this->auditMembership($space, $userId, $previousRole, null);
        }
        $this->authorizationService->invalidateUser($userId);
    }

    /**
     * @param  array<string, mixed>  $onCreate  Written only when the membership
     *                                          is first created.
     */
    private function upsertPivot(
        BelongsToMany $relation,
        string $userId,
        array $attributes,
        array $onCreate = [],
    ): void {
        if (! $relation->wherePivot('user_id', $userId)->exists()) {
            $relation->attach($userId, $attributes + $onCreate + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $relation->updateExistingPivot($userId, $attributes + [
            'updated_at' => now(),
        ]);
    }

    /**
     * Pivot attach/detach fire no Eloquent model events, so the Auditable
     * trait never sees membership changes — log them explicitly here.
     */
    private function auditMembership(Team|Space $entity, string $userId, ?Role $previousRole, ?Role $newRole): void
    {
        if ($previousRole?->id === $newRole?->id) {
            return;
        }

        $action = match (true) {
            $previousRole === null => 'member_added',
            $newRole === null => 'member_removed',
            default => 'member_role_changed',
        };

        $this->auditService->log(
            action: $action,
            entity: $entity,
            oldValues: $previousRole ? ['role' => $previousRole->key] : null,
            newValues: $newRole ? ['role' => $newRole->key] : null,
            metadata: ['member_id' => $userId],
        );
    }
}
