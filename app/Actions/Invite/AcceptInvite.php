<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Auth\MembershipService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptInvite
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function execute(Invite $invite, Authenticatable|User $user, ?string $token): Invite
    {
        return DB::transaction(function () use ($invite, $user, $token) {
            /** @var Invite $lockedInvite */
            $lockedInvite = Invite::query()
                ->with(['space.team', 'team', 'roleDefinition'])
                ->lockForUpdate()
                ->findOrFail($invite->id);

            $this->ensureCanAccept($lockedInvite, $user, $token);

            $lockedInvite->forceFill([
                'accepted_at' => now(),
                'invitee_id' => $user->id,
            ])->save();

            $this->handleInvite($lockedInvite, $user);

            return $lockedInvite->refresh();
        });
    }

    private function handleInvite(Invite $invite, User $user): void
    {
        if ($invite->space_id) {
            $this->membershipService->assignSpaceRole($invite->space, $user, $invite->role);
            $this->authorizationService->invalidateSpace($invite->space);
        } elseif ($invite->team_id) {
            $this->membershipService->assignTeamRole($invite->team, $user, $invite->role);
            $this->authorizationService->invalidateTeamTree($invite->team);
        }
    }

    private function ensureCanAccept(Invite $invite, User $user, ?string $token): void
    {
        // The mailed token proves possession of the invite link. It is
        // optional because the email-match check below is an equivalent
        // proof: the authenticated account owns the invited (verified)
        // address. When a token IS supplied it must match.
        if ($token !== null && ! hash_equals($invite->token, $token)) {
            throw ValidationException::withMessages([
                'token' => 'This invitation link is invalid.',
            ]);
        }

        if ($invite->isDeclined()) {
            throw ValidationException::withMessages([
                'invite' => 'This invitation has been declined.',
            ]);
        }

        if ($invite->isAccepted()) {
            throw ValidationException::withMessages([
                'invite' => 'This invitation has already been accepted.',
            ]);
        }

        if ($invite->isExpired()) {
            throw ValidationException::withMessages([
                'invite' => 'This invitation has expired.',
            ]);
        }

        if (strcasecmp($invite->email, $user->email) !== 0) {
            throw ValidationException::withMessages([
                'email' => 'You must use the invited email address to accept this invitation.',
            ]);
        }
    }
}
