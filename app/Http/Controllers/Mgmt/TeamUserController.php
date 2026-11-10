<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\TeamMemberListResource;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use App\Services\Team\TeamMemberDirectoryService;
use App\Services\Team\TeamScope;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TeamUserController extends Controller
{
    public function __construct(
        private TeamService $teamService,
        private readonly TeamMemberDirectoryService $directory,
        private readonly MembershipGuard $guard,
    ) {}

    /**
     * Attach user to team
     */
    public function store(Request $request, Team $team): TeamMemberListResource|JsonResponse
    {
        $this->authorize('manageMembers', $team);

        $request->validate([
            'user_id' => 'required|string|exists:users,id',
            'role' => 'nullable|string|max:50',
        ]);

        // Role changes go through update(), which checks the member's rank.
        // Upserting here would let an admin demote an owner.
        abort_if($team->users()->where('users.id', $request->user_id)->exists(), 422, 'User is already a member of this team.');

        $this->ensureKnownToOrganisation($request, $team, $request->user_id);

        $this->guard->ensureCanAssignTeamRole($request->user(), $team, $request->role ?? 'member');

        try {
            $this->teamService->attachUser(
                $team,
                $request->user_id,
                $request->role
            );

            return new TeamMemberListResource($this->loadTeamUser($team, $request->user_id));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to attach user to team.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update user role in team
     */
    public function update(Request $request, Team $team, string $userId): TeamMemberListResource|JsonResponse
    {
        $this->authorize('manageMembers', $team);

        $request->validate([
            'role' => 'nullable|string|max:50',
        ]);

        // A role inherited from a parent team is managed where it is granted.
        if ($this->directory->findMember($team, $userId)?->membership_origin === 'inherited') {
            return response()->json([
                'message' => 'This role is inherited from a parent team. Change it there.',
            ], 422);
        }

        if ($request->role) {
            $this->ensureKnownToOrganisation($request, $team, $userId);
        }

        $this->guard->ensureCanManageTeamMember($request->user(), $team, $userId);
        if ($request->role) {
            $this->guard->ensureCanAssignTeamRole($request->user(), $team, $request->role);
        }

        try {
            $this->teamService->updateUserRole($team, $userId, $request->role);

            return new TeamMemberListResource($this->loadTeamUser($team, $userId));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update user role.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Detach user from team
     */
    public function destroy(Request $request, Team $team, string $userId): Response|JsonResponse
    {
        $this->authorize('manageMembers', $team);

        if (! $team->users()->where('users.id', $userId)->exists()) {
            return response()->json([
                'message' => 'User does not have a direct team membership.',
            ], 404);
        }

        $this->guard->ensureCanManageTeamMember($request->user(), $team, $userId);

        try {
            $this->teamService->detachUser($team, $userId);

            return response()->noContent();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to detach user from team.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function loadTeamUser(Team $team, string $userId): User
    {
        return $this->directory->findMember($team, $userId) ?? abort(404);
    }

    /**
     * A direct role needs no consent of the user and shows the team their name
     * and email, so it is only for people the organisation already works with.
     * Anyone else gets an invite.
     */
    private function ensureKnownToOrganisation(Request $request, Team $team, string $userId): void
    {
        abort_unless(
            $request->user()->isElevatedRoot() || TeamScope::forOrganisationOf($team)->includesUser($userId),
            422,
            'User is not part of this organisation. Invite them instead.',
        );
    }
}
