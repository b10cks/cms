<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\TeamResource;
use App\Models\Management\Team;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\TeamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TeamHierarchyController extends Controller
{
    public function __construct(private TeamService $teamService)
    {
    }

    /**
     * Get team hierarchy starting from specified parent (or root if null),
     * scoped to the teams the user may access (root sees everything).
     */
    public function __invoke(Request $request, AuthorizationService $authorizationService): ResourceCollection
    {
        $this->authorize('viewAny', Team::class);

        $user = $request->user();
        $accessibleIds = $user->is_root ? null : $authorizationService->accessibleTeamIds($user);

        $parentId = $request->get('parent_id');
        $teams = $this->teamService->getTeamHierarchy($parentId, $accessibleIds);

        return TeamResource::collection($teams);
    }
}
