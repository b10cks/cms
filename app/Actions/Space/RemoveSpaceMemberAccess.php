<?php

namespace App\Actions\Space;

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Auth\MembershipService;

class RemoveSpaceMemberAccess
{
    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    public function execute(Space $space, User $user): void
    {
        $this->membershipService->removeSpaceMembership($space, $user);
    }
}
