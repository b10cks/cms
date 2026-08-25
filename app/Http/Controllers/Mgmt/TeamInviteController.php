<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Invite\CreateInvite;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\InviteFilter;
use App\Http\Requests\Invite\StoreInviteRequest;
use App\Http\Resources\Management\InviteResource;
use App\Models\Management\Invite;
use App\Models\Management\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TeamInviteController extends Controller
{
    public function index(Team $team, Request $request): ResourceCollection
    {
        $this->authorize('viewInvites', $team);

        $invites = Invite::filter(InviteFilter::fromRequest($request))
            ->leftJoin('roles', 'roles.id', '=', 'invites.role_id')
            ->where('invites.team_id', $team->id)
            ->with(['inviter', 'invitee', 'roleDefinition'])
            ->select('invites.*', 'roles.key as role_key')
            ->paginate();

        return InviteResource::collection($invites);
    }

    public function store(StoreInviteRequest $request, Team $team, CreateInvite $createInvite): JsonResponse
    {
        $this->authorize('manageInvites', $team);

        try {
            $invite = $createInvite->execute(
                [
                    'email' => $request->input('email'),
                    'role' => $request->input('role'),
                    'team_id' => $team->id,
                    'message' => $request->input('message'),
                    'language' => $request->input('language'),
                    'expires_at' => $request->getExpiresAt(),
                ],
                auth()->user()
            );

            return (new InviteResource($invite->loadMissing(['roleDefinition', 'inviter', 'invitee'])))
                ->response()
                ->setStatusCode(201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create team invite', [
                'team_id' => $team->id,
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while creating the invite',
            ], 500);
        }
    }

    public function destroy(Team $team, Invite $invite): JsonResponse
    {
        $this->authorize('delete', $invite);

        if ($invite->team_id !== $team->id) {
            return response()->json([
                'message' => 'The invite does not belong to this team',
            ], 404);
        }

        try {
            $invite->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to revoke team invite', [
                'invite_id' => $invite->id,
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while revoking the invite',
            ], 500);
        }
    }
}
