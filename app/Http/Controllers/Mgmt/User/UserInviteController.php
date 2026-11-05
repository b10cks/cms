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
            $query->where('invitee_id', $user->id)
                ->when($user->ownsVerifiedEmail(), fn ($query) => $query->orWhere('email', $user->email));
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
        $matchesEmail = $user->ownsVerifiedEmail() && strcasecmp($invite->email, $user->email) === 0;
        abort_if(! $matchesEmail && $invite->invitee_id !== $user->id, 403);

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

    /**
     * Same proof as accepting: the bound invitee, or the invited address backed
     * by the mailed token or a mail-verified account. A team IdP asserting the
     * address is not enough.
     */
    public function decline(Invite $invite, Request $request): InviteResource
    {
        $user = auth()->user();
        $token = $request->validate(['token' => 'nullable|string'])['token'] ?? null;
        abort_if($token !== null && ! hash_equals($invite->token, $token), 403);

        $ownsAddress = strcasecmp($invite->email, $user->email) === 0
            && ($token !== null || $user->ownsVerifiedEmail());
        abort_if(! $ownsAddress && $invite->invitee_id !== $user->id, 403);

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
