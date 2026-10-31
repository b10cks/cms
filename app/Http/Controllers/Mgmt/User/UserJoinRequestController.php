<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Actions\Team\RequestToJoinTeam;
use App\Enums\JoinRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\JoinableTeamResource;
use App\Http\Resources\Management\TeamJoinRequestResource;
use App\Models\Management\Team;
use App\Models\Management\TeamJoinRequest;
use App\Services\Team\TeamDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** The requester's side: teams their email domain lets them ask to join, and their requests. */
class UserJoinRequestController extends Controller
{
    public function index(Request $request, TeamDomainService $domains): AnonymousResourceCollection
    {
        return JoinableTeamResource::collection($domains->joinableDomains($request->user()));
    }

    public function store(Request $request, RequestToJoinTeam $requestToJoin): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'string'],
        ]);

        $team = Team::query()->find($validated['team_id']);
        if (! $team) {
            throw ValidationException::withMessages([
                'team_id' => [__('You cannot request to join this team.')],
            ]);
        }

        $joinRequest = $requestToJoin->execute($request->user(), $team);

        return (new TeamJoinRequestResource($joinRequest))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, TeamJoinRequest $joinRequest): Response
    {
        abort_unless($joinRequest->user_id === $request->user()->id, 404);

        if ($joinRequest->status !== JoinRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'join_request' => [__('Only pending requests can be cancelled.')],
            ]);
        }

        $joinRequest->delete();

        return response()->noContent();
    }
}
