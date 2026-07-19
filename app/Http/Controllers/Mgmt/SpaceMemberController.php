<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Space\RemoveSpaceMemberAccess;
use App\Actions\Space\UpdateSpaceMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\SpaceMemberListResource;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use App\Services\Space\SpaceMemberDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
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
}
