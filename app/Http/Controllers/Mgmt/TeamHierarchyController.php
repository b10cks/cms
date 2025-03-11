<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\TeamResource;
use App\Models\Management\Team;
use App\Services\Team\TeamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TeamHierarchyController extends Controller
{
    public function __construct(private TeamService $teamService)
    {
    }

    /**
     * Get team hierarchy starting from specified parent (or root if null)
     */
    public function __invoke(Request $request): ResourceCollection
    {
        $this->authorize('viewAny', Team::class);

        $parentId = $request->get('parent_id');
        $teams = $this->teamService->getTeamHierarchy($parentId);

        return TeamResource::collection($teams);
    }
}
