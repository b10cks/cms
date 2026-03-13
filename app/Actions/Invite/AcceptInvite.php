<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Auth\MembershipService;
use Illuminate\Contracts\Auth\Authenticatable;

class AcceptInvite
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function execute(Invite $invite, Authenticatable|User $user): bool
    {
        if ($invite->isAccepted() || $invite->isExpired()) {
            return false;
        }

        $invite->accepted_at = now();
        $invite->invitee_id = $user->id;
        $invite->save();
        $this->handleInvite($invite, $user);

        return true;
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
}
