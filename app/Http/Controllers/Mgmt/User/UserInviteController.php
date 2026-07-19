<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Actions\Invite\AcceptInvite;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\InviteFilter;
use App\Http\Requests\Invite\AcceptInviteRequest;
use App\Http\Resources\Management\InviteResource;
use App\Models\Management\Invite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserInviteController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $user = auth()->user();

        $invites = Invite::where(function ($query) use ($user) {
            $query->where('email', $user->email)
                ->orWhere('invitee_id', $user->id);
        })
            ->leftJoin('roles', 'roles.id', '=', 'invites.role_id')
            ->with(['inviter', 'space', 'team', 'roleDefinition'])
            ->select('invites.*', 'roles.key as role_key')
            ->filter(InviteFilter::fromRequest($request))
            ->paginate();

        return InviteResource::collection($invites);
    }

    public function show(Invite $invite): InviteResource|JsonResponse
    {
        $user = auth()->user();
        abort_if($invite->email !== $user->email && $invite->invitee_id !== $user->id, 403);

        return new InviteResource(
            $invite->load(['inviter', 'space', 'team', 'roleDefinition'])
        );
    }

    public function accept(Invite $invite, AcceptInviteRequest $request, AcceptInvite $acceptInvite): InviteResource|JsonResponse
    {
        $user = auth()->user();

        try {
            $token = $request->filled('token') ? $request->string('token')->toString() : null;
            $acceptedInvite = $acceptInvite->execute($invite, $user, $token);

            return new InviteResource($acceptedInvite->loadMissing(['inviter', 'space', 'team', 'roleDefinition']));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to accept invite', [
                'invite_id' => $invite->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while accepting the invite',
            ], 500);
        }
    }

    public function decline(Invite $invite): InviteResource
    {
        $user = auth()->user();
        abort_if(strcasecmp($invite->email, $user->email) !== 0 && $invite->invitee_id !== $user->id, 403);

        if (! $invite->isPending()) {
            throw ValidationException::withMessages([
                'invite' => ['Only pending invitations can be declined.'],
            ]);
        }

        $invite->forceFill([
            'declined_at' => now(),
            'invitee_id' => $invite->invitee_id ?? $user->id,
        ])->save();

        return new InviteResource($invite->loadMissing(['inviter', 'space', 'team', 'roleDefinition']));
    }
}
