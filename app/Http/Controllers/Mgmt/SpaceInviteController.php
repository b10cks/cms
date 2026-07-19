<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Invite\CreateInvite;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\InviteFilter;
use App\Http\Requests\Invite\StoreInviteRequest;
use App\Http\Resources\Management\InviteResource;
use App\Models\Management\Invite;
use App\Models\Management\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SpaceInviteController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewInvites', $space);

        $invites = Invite::filter(InviteFilter::fromRequest($request))
            ->leftJoin('roles', 'roles.id', '=', 'invites.role_id')
            ->where('space_id', $space->id)
            ->with(['inviter', 'invitee', 'roleDefinition'])
            ->select('invites.*', 'roles.key as role_key')
            ->paginate();

        return InviteResource::collection($invites);
    }

    public function store(StoreInviteRequest $request, Space $space, CreateInvite $createInvite): JsonResponse
    {
        $this->authorize('manageInvites', $space);

        try {
            $invite = $createInvite->execute(
                [
                    'email' => $request->input('email'),
                    'role' => $request->input('role'),
                    'space_id' => $space->id,
                    'message' => $request->input('message'),
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
            Log::error('Failed to create space invite', [
                'space_id' => $space->id,
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while creating the invite',
            ], 500);
        }
    }

    public function destroy(Space $space, Invite $invite): JsonResponse
    {
        $this->authorize('delete', $invite);

        if ($invite->space_id !== $space->id) {
            return response()->json([
                'message' => 'The invite does not belong to this space',
            ], 404);
        }

        try {
            $invite->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to revoke space invite', [
                'invite_id' => $invite->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while revoking the invite',
            ], 500);
        }
    }
}
