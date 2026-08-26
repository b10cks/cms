<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Team\RemoveTeamMemberAccess;
use App\Actions\Team\UpdateTeamMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\TeamMemberListResource;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use App\Services\Team\TeamMemberDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TeamMemberController extends Controller
{
    public function __construct(
        private readonly TeamMemberDirectoryService $directory,
        private readonly MembershipGuard $guard,
    ) {}

    public function index(Request $request, Team $team): ResourceCollection
    {
        $this->authorize('viewMembers', $team);

        $members = $this->directory->paginate($team, $request->all())
            ->appends($request->query());

        return TeamMemberListResource::collection($members);
    }

    public function update(Request $request, Team $team, User $user, UpdateTeamMemberRole $updateRole): Response|JsonResponse
    {
        $this->authorize('manageMembers', $team);

        $member = $this->directory->findMember($team, $user->id);

        if (! $member) {
            return response()->json([
                'message' => 'User is not visible in this team.',
            ], 404);
        }

        // A role inherited from a parent team is managed where it is granted.
        // Giving someone extra authority in this team is the separate, explicit
        // "add member" action.
        if ($member->membership_origin === 'inherited') {
            return response()->json([
                'message' => 'This role is inherited from a parent team. Change it there.',
            ], 422);
        }

        $request->validate([
            'role' => 'nullable|string|max:50',
        ]);

        $this->guard->ensureCanManageTeamMember($request->user(), $team, $user);
        if ($request->role) {
            $this->guard->ensureCanAssignTeamRole($request->user(), $team, $request->role);
        }

        try {
            $updateRole->execute($team, $user, $request->role);

            return response()->noContent();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update member role.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Request $request, Team $team, User $user, RemoveTeamMemberAccess $removeAccess): Response|JsonResponse
    {
        $this->authorize('manageMembers', $team);

        if (! $team->users()->where('users.id', $user->id)->exists()) {
            return response()->json([
                'message' => 'User does not have a direct team membership.',
            ], 404);
        }

        $this->guard->ensureCanManageTeamMember($request->user(), $team, $user);

        try {
            $removeAccess->execute($team, $user);

            return response()->noContent();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to remove member access.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
