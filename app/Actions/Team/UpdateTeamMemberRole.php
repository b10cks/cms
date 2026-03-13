<?php

namespace App\Actions\Team;

use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipService;

class UpdateTeamMemberRole
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function execute(Team $team, User $user, ?string $role): void
    {
        if (! $role) {
            $this->membershipService->removeTeamMembership($team, $user);

            return;
        }

        $this->membershipService->assignTeamRole($team, $user, $role);
    }
}
