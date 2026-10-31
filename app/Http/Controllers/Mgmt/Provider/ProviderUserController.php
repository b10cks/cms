<?php

namespace App\Http\Controllers\Mgmt\Provider;

use App\Actions\Provider\RestoreUser;
use App\Actions\Provider\SignOutEverywhere;
use App\Actions\Provider\SuspendUser;
use App\Actions\Provider\TransferTeamOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ProviderUserActionRequest;
use App\Http\Requests\Provider\SuspendUserRequest;
use App\Http\Requests\Provider\TransferOwnershipRequest;
use App\Http\Resources\Management\Provider\ProviderUserResource;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Provider\UserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The provider console's user directory and the root actions on a user. */
class ProviderUserController extends Controller
{
    public function index(Request $request, UserDirectory $directory): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'segment' => ['sometimes', Rule::in(UserDirectory::SEGMENTS)],
        ]);

        $users = $directory
            ->query($validated['search'] ?? null, $validated['segment'] ?? 'all')
            ->paginate($this->perPage($request, 25, 100));

        return ProviderUserResource::collection($users)->additional([
            'meta' => ['segments' => $directory->segmentCounts()],
        ]);
    }

    public function show(User $user, UserDirectory $directory): JsonResponse
    {
        $user->loadCount(['teams', 'samlIdentities'])->load('socialLinks:id,user_id,service');

        return response()->json([
            'data' => [
                ...(new ProviderUserResource($user))->resolve(),
                'suspended_by' => $user->suspended_by ? User::query()->find($user->suspended_by)?->email : null,
                ...$directory->detail($user),
            ],
        ]);
    }

    public function suspend(SuspendUserRequest $request, User $user, SuspendUser $suspend): JsonResponse
    {
        $ownerless = $suspend->execute(
            $request->user(),
            $user,
            $request->string('reason')->trim()->toString(),
            $request->replacementOwners(),
        );

        return response()->json(['data' => ['ownerless_team_ids' => $ownerless]]);
    }

    public function restore(ProviderUserActionRequest $request, User $user, RestoreUser $restore): JsonResponse
    {
        $restore->execute($request->user(), $user);

        return response()->json(null, 204);
    }

    public function signOut(ProviderUserActionRequest $request, User $user, SignOutEverywhere $signOut): JsonResponse
    {
        $signOut->execute($request->user(), $user);

        return response()->json(null, 204);
    }

    public function transferOwnership(TransferOwnershipRequest $request, User $user, TransferTeamOwnership $transfer): JsonResponse
    {
        $transfer->execute(
            $request->user(),
            Team::query()->findOrFail($request->string('team_id')->toString()),
            $user,
            User::query()->findOrFail($request->string('new_owner_id')->toString()),
        );

        return response()->json(null, 204);
    }
}
