<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Team\RemoveTeamMemberAccess;
use App\Actions\Team\UpdateTeamMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\TeamMemberListResource;
use App\Models\Management\Team;
use App\Services\Team\TeamMemberDirectoryService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

class TeamMemberController extends Controller
{
    public function __construct(private readonly TeamMemberDirectoryService $directory)
    {
    }

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

        if (! $this->directory->findMember($team, $user->id)) {
            return response()->json([
                'message' => 'User is not visible in this team.',
            ], 404);
        }

        $request->validate([
            'role' => 'nullable|string|max:50',
        ]);

        try {
            $updateRole->execute($team, $user, $request->role);

            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update member role.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Team $team, User $user, RemoveTeamMemberAccess $removeAccess): Response|JsonResponse
    {
        $this->authorize('manageMembers', $team);

        if (! $team->users()->where('users.id', $user->id)->exists()) {
            return response()->json([
                'message' => 'User does not have a direct team membership.',
            ], 404);
        }

        try {
            $removeAccess->execute($team, $user);

            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to remove member access.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
