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
            ->paginate($request->get('per_page', 20));

        return TeamResource::collection($teams);
    }

    /**
     * Store a newly created team
     */
    public function store(StoreTeamRequest $request): TeamResource|JsonResponse
    {
        $this->authorize('create', Team::class);

        try {
            $team = $this->teamService->createTeam($request->validated());

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

        try {
            $team = $this->teamService->updateTeam($team, $request->validated());

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
