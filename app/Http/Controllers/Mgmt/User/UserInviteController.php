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

class UserInviteController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $user = auth()->user();

        $invites = Invite::where(function ($query) use ($user) {
            $query->where('email', $user->email)
                ->orWhere('invitee_id', $user->id);
        })
            ->with(['inviter', 'space', 'team'])
            ->filter(InviteFilter::fromRequest($request))
            ->paginate();

        return InviteResource::collection($invites);
    }

    public function show(Invite $invite): JsonResponse
    {
        $user = auth()->user();

        if ($invite->email !== $user->email && $invite->invitee_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json(new InviteResource(
            $invite->load(['inviter', 'space', 'team'])
        ));
    }

    public function accept(Invite $invite, AcceptInviteRequest $request, AcceptInvite $acceptInvite): JsonResponse
    {
        $user = auth()->user();

        if ($invite->email !== $user->email && $invite->invitee_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($request->input('token') !== $invite->token) {
            return response()->json([
                'message' => 'Invalid invitation token'
            ], 422);
        }

        try {
            if (!$acceptInvite->execute($invite, $user)) {
                return response()->json([
                    'message' => 'The invitation cannot be accepted'
                ], 422);
            }

            return response()->json(new InviteResource($invite->refresh()->load(['inviter', 'space', 'team'])));
        } catch (\Exception $e) {
            Log::error('Failed to accept invite', [
                'invite_id' => $invite->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while accepting the invite'
            ], 500);
        }
    }
}
