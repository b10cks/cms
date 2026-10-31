<?php

namespace App\Actions\Provider;

use App\Enums\InstanceAuditAction;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Audit\InstanceAuditService;
use App\Services\Auth\MembershipService;
use App\Services\Team\TeamOwnershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blocks a user from signing in, ends their sessions and revokes their
 * personal access tokens. Memberships stay, so restoring loses nothing.
 * Teams the user was the only owner of get the given replacement owner, or
 * show up as "No owner" in Tenants.
 */
class SuspendUser
{
    public function __construct(
        private readonly SignOutEverywhere $signOut,
        private readonly MembershipService $memberships,
        private readonly TeamOwnershipService $ownership,
        private readonly InstanceAuditService $audit,
    ) {}

    /**
     * @param  array<string, string>  $replacementOwners  team id => new owner user id
     * @return array<int, string> Ids of teams left without an owner.
     */
    public function execute(User $actor, User $user, string $reason, array $replacementOwners = []): array
    {
        $soleOwned = $this->ownership->soleOwnedTeamIds($user);

        foreach ($replacementOwners as $teamId => $ownerId) {
            if (! in_array($teamId, $soleOwned, true) || $ownerId === $user->id) {
                throw ValidationException::withMessages(['replacement_owners' => [__('provider.invalid_new_owner')]]);
            }
        }

        $replacements = User::query()->whereIn('id', array_values($replacementOwners))->whereNull('suspended_at')->get()->keyBy('id');

        DB::transaction(function () use ($actor, $user, $reason, $replacementOwners, $replacements) {
            foreach ($replacementOwners as $teamId => $ownerId) {
                $owner = $replacements->get($ownerId)
                    ?? throw ValidationException::withMessages(['replacement_owners' => [__('provider.invalid_new_owner')]]);

                $this->memberships->assignTeamRole(Team::query()->findOrFail($teamId), $owner, 'owner');
            }

            $user->forceFill([
                'suspended_at' => now(),
                'suspended_by' => $actor->id,
                'suspension_reason' => $reason,
            ])->save();

            $this->signOut->execute($actor, $user, audit: false);
            $user->tokens()->delete();
        });

        $ownerless = array_values(array_diff($soleOwned, array_keys($replacementOwners)));

        $this->audit->record(InstanceAuditAction::UserSuspended, $user, $actor, [
            'user_email' => $user->email,
            'reason' => $reason,
            'replacement_owners' => $replacementOwners,
            'ownerless_team_ids' => $ownerless,
        ]);

        return $ownerless;
    }
}
