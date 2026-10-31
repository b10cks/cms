<?php

namespace App\Actions\Team;

use App\Enums\JoinRequestStatus;
use App\Enums\MembershipSource;
use App\Models\Management\TeamJoinRequest;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use App\Services\Auth\MembershipService;
use App\Services\System\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves or declines a pending join request. Approval grants the chosen
 * role, or the domain's default role, within the actor's MembershipGuard
 * ceiling.
 */
class DecideJoinRequest
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly MembershipGuard $guard,
        private readonly AuditService $auditService,
    ) {}

    public function approve(User $actor, TeamJoinRequest $joinRequest, ?string $roleKey = null): TeamJoinRequest
    {
        return DB::transaction(function () use ($actor, $joinRequest, $roleKey) {
            $locked = $this->lockPending($joinRequest);
            $team = $locked->team;
            $roleKey ??= $locked->domain?->defaultRoleKey() ?? 'member';

            // Someone invited or added in the meantime keeps the role they have.
            if (! $team->users()->where('users.id', $locked->user_id)->exists()) {
                $this->guard->ensureCanAssignTeamRole($actor, $team, $roleKey);
                $this->membershipService->assignTeamRole($team, $locked->user_id, $roleKey, MembershipSource::Domain);
            }

            return $this->decide($actor, $locked, JoinRequestStatus::Approved, ['role' => $roleKey]);
        });
    }

    public function decline(User $actor, TeamJoinRequest $joinRequest): TeamJoinRequest
    {
        return DB::transaction(fn () => $this->decide(
            $actor,
            $this->lockPending($joinRequest),
            JoinRequestStatus::Declined,
        ));
    }

    private function lockPending(TeamJoinRequest $joinRequest): TeamJoinRequest
    {
        /** @var TeamJoinRequest $locked */
        $locked = TeamJoinRequest::query()
            ->with(['team', 'domain.defaultRole'])
            ->lockForUpdate()
            ->findOrFail($joinRequest->id);

        if ($locked->status !== JoinRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'join_request' => [__('This request has already been decided.')],
            ]);
        }

        return $locked;
    }

    private function decide(User $actor, TeamJoinRequest $joinRequest, JoinRequestStatus $status, array $newValues = []): TeamJoinRequest
    {
        $joinRequest->forceFill([
            'status' => $status,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ])->save();

        $this->auditService->log(
            action: "join_request_{$status->value}",
            entity: $joinRequest->team,
            newValues: $newValues ?: null,
            metadata: [
                'join_request_id' => $joinRequest->id,
                'member_id' => $joinRequest->user_id,
                'domain' => $joinRequest->domain?->domain,
            ],
            user: $actor,
        );

        return $joinRequest;
    }
}
