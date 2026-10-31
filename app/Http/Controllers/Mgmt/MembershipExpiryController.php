<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\UpdateMembershipExpiryRequest;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use App\Services\Auth\MembershipService;
use Illuminate\Http\Response;

/** Sets the "access until" date of a direct team or space membership. */
class MembershipExpiryController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly MembershipGuard $guard,
    ) {}

    public function team(UpdateMembershipExpiryRequest $request, Team $team, User $user): Response
    {
        $this->authorize('manageMembers', $team);
        abort_unless($team->users()->where('users.id', $user->id)->exists(), 404, 'User does not have a direct team membership.');

        $this->guard->ensureCanManageTeamMember($request->user(), $team, $user);

        $expiresAt = $request->expiresAt();
        if ($expiresAt) {
            // The sweep would have to keep the last owner anyway; refuse up front.
            $this->guard->ensureTeamRetainsOwner($team, $user);
        }

        $this->memberships->setMembershipExpiry($team, $user, $expiresAt);

        return response()->noContent();
    }

    public function space(UpdateMembershipExpiryRequest $request, Space $space, User $user): Response
    {
        $this->authorize('manageMembers', $space);
        abort_unless($space->users()->where('users.id', $user->id)->exists(), 404, 'User is not a member of this space.');

        $this->guard->ensureCanManageSpaceMember($request->user(), $space, $user);

        $this->memberships->setMembershipExpiry($space, $user, $request->expiresAt());

        return response()->noContent();
    }
}
