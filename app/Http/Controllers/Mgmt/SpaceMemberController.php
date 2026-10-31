<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Space\RemoveSpaceMemberAccess;
use App\Actions\Space\UpdateSpaceMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\SpaceMemberListResource;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use App\Services\Space\SpaceMemberDirectoryService;
use App\Services\Team\TeamScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpaceMemberController extends Controller
{
    public function __construct(
        private readonly SpaceMemberDirectoryService $directory,
        private readonly MembershipGuard $guard,
    ) {}

    public function index(Request $request, Space $space): ResourceCollection
    {
        $this->authorize('viewMembers', $space);

        $members = $this->directory->paginate($space, $request->all())
            ->appends($request->query());

        return SpaceMemberListResource::collection($members);
    }

    /**
     * Give someone from the space's organisation a direct role here (the
     * access matrix "+"). Anyone else has to be invited, so a user id alone
     * never reaches across tenants.
     */
    public function store(Request $request, Space $space, UpdateSpaceMemberRole $updateRole): Response
    {
        $this->authorize('manageMembers', $space);

        $data = $request->validate([
            'user_id' => 'required|string|exists:users,id',
            'role' => 'required|string|max:50',
        ]);

        abort_if($space->users()->where('users.id', $data['user_id'])->exists(), 422, 'User is already a member of this space.');
        abort_unless($space->team_id && $this->belongsToOrganisation($space, $data['user_id']), 422, 'User is not part of this organisation. Invite them instead.');

        $this->guard->ensureCanAssignSpaceRole($request->user(), $space, $data['role']);

        $updateRole->execute($space, User::findOrFail($data['user_id']), $data['role']);

        return response()->noContent();
    }

    public function update(Request $request, Space $space, User $user, UpdateSpaceMemberRole $updateRole): Response|JsonResponse
    {
        $this->authorize('manageMembers', $space);

        if (! $this->directory->findMember($space, $user->id)) {
            return response()->json([
                'message' => 'User is not a member of this space.',
            ], 404);
        }

        $request->validate([
            'role' => 'nullable|string|max:50',
        ]);

        $this->guard->ensureCanManageSpaceMember($request->user(), $space, $user);
        if ($request->role) {
            $this->guard->ensureCanAssignSpaceRole($request->user(), $space, $request->role);
        }

        try {
            $updateRole->execute($space, $user, $request->role);

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

    public function destroy(Request $request, Space $space, User $user, RemoveSpaceMemberAccess $removeAccess): Response|JsonResponse
    {
        $this->authorize('manageMembers', $space);

        if (! $space->users()->where('users.id', $user->id)->exists()) {
            return response()->json([
                'message' => 'User is not a member of this space.',
            ], 404);
        }

        $this->guard->ensureCanManageSpaceMember($request->user(), $space, $user);

        try {
            $removeAccess->execute($space, $user);

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

    private function belongsToOrganisation(Space $space, string $userId): bool
    {
        $root = $space->team;
        while ($root->parent_id && ($parent = Team::query()->find($root->parent_id))) {
            $root = $parent;
        }

        $scope = TeamScope::for($root);

        return DB::table('team_user')->whereIn('team_id', $scope->teamIds)->where('user_id', $userId)->exists()
            || DB::table('space_user')->whereIn('space_id', $scope->spaceIds)->where('user_id', $userId)->exists();
    }
}
