<?php

namespace App\Actions\Provider;

use App\Enums\InstanceAuditAction;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Audit\InstanceAuditService;
use App\Services\Auth\MembershipGuard;
use App\Services\Auth\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes `$to` an owner of the team and steps `$from` down to admin. The new
 * owner is in place before the old one is demoted, so the last-owner rule in
 * MembershipService holds throughout.
 */
class TransferTeamOwnership
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly MembershipGuard $guard,
        private readonly InstanceAuditService $audit,
    ) {}

    public function execute(User $actor, Team $team, User $from, User $to): void
    {
        if ($this->guard->memberTeamRole($team, $from)?->key !== 'owner') {
            throw ValidationException::withMessages(['team_id' => [__('provider.not_owner')]]);
        }

        if ($to->id === $from->id || $to->isSuspended()) {
            throw ValidationException::withMessages(['new_owner_id' => [__('provider.invalid_new_owner')]]);
        }

        $this->guard->ensureCanAssignTeamRole($actor, $team, 'owner');

        DB::transaction(function () use ($team, $from, $to) {
            $this->memberships->assignTeamRole($team, $to, 'owner');
            $this->memberships->assignTeamRole($team, $from, 'admin');
        });

        $this->audit->record(InstanceAuditAction::OwnershipTransferred, $team, $actor, [
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'team_name' => $team->name,
        ]);
    }
}
