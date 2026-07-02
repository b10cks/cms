<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\TeamFilter;
use App\Http\Requests\Team\ListTeamsRequest;
use App\Http\Requests\Team\StoreTeamRequest;
use App\Http\Requests\Team\UpdateTeamRequest;
use App\Http\Resources\Management\TeamResource;
use App\Models\Management\Team;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

class TeamController extends Controller
{
    public function __construct(private TeamService $teamService) {}

    /**
     * Display a listing of teams
     */
    public function index(ListTeamsRequest $request, AuthorizationService $authorizationService): ResourceCollection
    {
        $filter = new TeamFilter($request->all());
        $user = auth()->user();
        $includeSpaceContext = $request->boolean('include_space_context');

        if (! $includeSpaceContext) {
            $this->authorize('viewAny', Team::class);
        } elseif (! $user->is_root
            && $authorizationService->accessibleTeamIds($user) === []
            && $authorizationService->accessibleSpaceIds($user) === []) {
            abort(403);
        }

        $accessibleTeamIds = $includeSpaceContext
            ? $authorizationService->selectorAccessibleTeamIds($user)
            : $authorizationService->accessibleTeamIds($user);

        $teams = Team::filter($filter)
            ->when(! $user->is_root, function ($query) use ($accessibleTeamIds) {
                $query->whereIn('teams.id', $accessibleTeamIds);
            })
            ->with(['parent'])
            ->withCount(['users', 'spaces', 'children'])
            ->paginate($this->perPage($request));

        return TeamResource::collection($teams);
    }

    /**
     * Store a newly created team
     */
    public function store(StoreTeamRequest $request): TeamResource|JsonResponse
    {
        $data = $request->validated();
        $parentId = $data['parent_id'] ?? null;

        if ($parentId) {
            $this->authorize('createChild', Team::findOrFail($parentId));
        } else {
            // Top-level teams may only be created by root.
            $this->authorize('create', Team::class);
        }

        try {
            $team = $this->teamService->createTeam($data, $request->user());

            $team->load(['parent'])
                ->loadCount(['users', 'spaces', 'children']);

            return new TeamResource($team);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create team.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Display the specified team
     */
    public function show(Team $team): TeamResource
    {
        $this->authorize('view', $team);

        $team->load(['parent', 'children', 'users', 'spaces'])
            ->loadCount(['users', 'spaces', 'children']);

        return new TeamResource($team);
    }

    /**
     * Update the specified team
     */
    public function update(UpdateTeamRequest $request, Team $team): TeamResource|JsonResponse
    {
        $this->authorize('update', $team);

        $data = $request->validated();

        // Re-parenting must be authorized against the destination, not just the
        // team being moved. Only root may move a team to / from the top level.
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $team->parent_id) {
            if ($data['parent_id'] === null) {
                abort_unless($request->user()->is_root, 403);
            } else {
                $this->authorize('createChild', Team::findOrFail($data['parent_id']));
            }
        }

        try {
            $team = $this->teamService->updateTeam($team, $data);

            $team->load(['parent'])
                ->loadCount(['users', 'spaces', 'children']);

            return new TeamResource($team);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update team.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove the specified team
     */
    public function destroy(Team $team): Response|JsonResponse
    {
        $this->authorize('delete', $team);

        try {
            $this->teamService->deleteTeam($team);

            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete team.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
