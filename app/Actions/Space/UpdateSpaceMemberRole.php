<?php

namespace App\Actions\Space;

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Auth\MembershipService;

class UpdateSpaceMemberRole
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function execute(Space $space, User $user, ?string $role): void
    {
        if (! $role) {
            $this->membershipService->removeSpaceMembership($space, $user);

            return;
        }

        $this->membershipService->assignSpaceRole($space, $user, $role);
    }
}
