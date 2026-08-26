<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\SpaceBlueprintFilter;
use App\Http\Resources\Management\SpaceBlueprintListResource;
use App\Models\Management\SpaceBlueprint;
use App\Services\Auth\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AvailableSpaceBlueprintController extends Controller
{
    /**
     * List every blueprint the caller may start a space from.
     *
     * That is the system blueprints plus the ones owned by a team the caller
     * can reach, directly or through an ancestor team.
     */
    public function __invoke(Request $request, AuthorizationService $authorization): ResourceCollection
    {
        $user = $request->user();
        $teamIds = $authorization->accessibleTeamIds($user);

        $blueprints = SpaceBlueprint::filter(SpaceBlueprintFilter::fromRequest($request))
            ->where(function ($query) use ($teamIds) {
                $query->whereNull('team_id')
                    ->orWhereIn('team_id', $teamIds);
            })
            ->with(['creator', 'team'])
            ->paginate($this->perPage($request));

        return SpaceBlueprintListResource::collection($blueprints);
    }
}
