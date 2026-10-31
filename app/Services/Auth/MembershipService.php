<?php

namespace App\Services\Auth;

use App\Enums\MembershipSource;
use App\Models\Management\Role;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

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

    /**
     * @param  string|null  $reason  Recorded in the audit metadata, e.g. `expired`.
     */
    public function removeTeamMembership(Team $team, User|string $user, ?string $reason = null): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $this->guard->ensureTeamRetainsOwner($team, $userId);

        $previousRole = $this->guard->memberTeamRole($team, $userId);
        if ($team->users()->detach($userId) > 0) {
            $this->auditMembership($team, $userId, $previousRole, null, $reason);
        }
        $this->authorizationService->invalidateUser($userId);
    }

    public function removeSpaceMembership(Space $space, User|string $user, ?string $reason = null): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $previousRole = $this->guard->memberSpaceRole($space, $userId);
        if ($space->users()->detach($userId) > 0) {
            $this->auditMembership($space, $userId, $previousRole, null, $reason);
        }
        $this->authorizationService->invalidateUser($userId);
    }

    /**
     * Set or clear the date a direct membership ends. `memberships:expire`
     * removes it once the date has passed.
     */
    public function setMembershipExpiry(Team|Space $entity, User|string $user, ?Carbon $expiresAt): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        $pivot = $entity->users()->newPivotStatement()
            ->where($entity instanceof Team ? 'team_id' : 'space_id', $entity->id)
            ->where('user_id', $userId);

        $previous = $pivot->clone()->value('expires_at');
        $previous = $previous ? Carbon::parse($previous) : null;

        if ($previous?->toIso8601String() === $expiresAt?->toIso8601String()) {
            return;
        }

        $pivot->update(['expires_at' => $expiresAt, 'updated_at' => now()]);

        $this->auditService->log(
            action: 'member_expiry_changed',
            entity: $entity,
            oldValues: ['expires_at' => $previous?->toIso8601String()],
            newValues: ['expires_at' => $expiresAt?->toIso8601String()],
            metadata: ['member_id' => $userId],
        );
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
    private function auditMembership(
        Team|Space $entity,
        string $userId,
        ?Role $previousRole,
        ?Role $newRole,
        ?string $reason = null,
    ): void {
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
            metadata: array_filter(['member_id' => $userId, 'reason' => $reason]),
        );
    }
}
