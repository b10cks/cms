<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Team\DecideJoinRequest;
use App\Enums\JoinRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\ApproveJoinRequestRequest;
use App\Http\Resources\Management\TeamJoinRequestResource;
use App\Models\Management\Team;
use App\Models\Management\TeamJoinRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Pending domain join requests, decided by anyone who manages the team's members. */
class TeamJoinRequestController extends Controller
{
    public function index(Team $team): AnonymousResourceCollection
    {
        $this->authorize('manageMembers', $team);

        return TeamJoinRequestResource::collection(
            $team->joinRequests()
                ->where('status', JoinRequestStatus::Pending)
                ->with(['user', 'domain.defaultRole'])
                ->oldest()
                ->get()
        );
    }

    public function approve(
        ApproveJoinRequestRequest $request,
        Team $team,
        TeamJoinRequest $joinRequest,
        DecideJoinRequest $decide,
    ): TeamJoinRequestResource {
        $this->authorize('manageMembers', $team);
        abort_unless($joinRequest->team_id === $team->id, 404);

        $role = $request->filled('role') ? $request->string('role')->toString() : null;

        return new TeamJoinRequestResource(
            $decide->approve($request->user(), $joinRequest, $role)->load(['user', 'domain.defaultRole'])
        );
    }

    public function decline(
        Request $request,
        Team $team,
        TeamJoinRequest $joinRequest,
        DecideJoinRequest $decide,
    ): TeamJoinRequestResource {
        $this->authorize('manageMembers', $team);
        abort_unless($joinRequest->team_id === $team->id, 404);

        return new TeamJoinRequestResource(
            $decide->decline($request->user(), $joinRequest)->load(['user', 'domain.defaultRole'])
        );
    }
}
