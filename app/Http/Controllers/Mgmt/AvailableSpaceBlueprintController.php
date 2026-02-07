<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\SpaceBlueprintFilter;
use App\Http\Resources\Management\SpaceBlueprintListResource;
use App\Models\Management\SpaceBlueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\DB;

class AvailableSpaceBlueprintController extends Controller
{
    public function __invoke(Request $request): ResourceCollection
    {
        $teamIds = DB::table('team_user')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->where('team_user.user_id', $request->user()->id)
            ->whereIn('roles.key', ['member', 'admin', 'owner'])
            ->pluck('team_user.team_id');

        $blueprints = SpaceBlueprint::filter(SpaceBlueprintFilter::fromRequest($request))
            ->where(function ($query) use ($teamIds) {
                $query->whereNull('team_id')
                    ->orWhereIn('team_id', $teamIds);
            })
            ->with(['creator', 'team'])
            ->paginate($request->get('per_page', 20));

        return SpaceBlueprintListResource::collection($blueprints);
    }
}
