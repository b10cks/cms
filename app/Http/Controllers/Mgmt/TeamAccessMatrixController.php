<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Team;
use App\Services\Team\TeamAccessMatrixService;
use App\Services\Team\TeamScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamAccessMatrixController extends Controller
{
    public function __invoke(Request $request, Team $team, TeamAccessMatrixService $matrix): JsonResponse
    {
        $this->authorize('viewMembers', $team);

        $request->validate(['team_id' => 'nullable|string']);

        $filter = null;
        if ($request->filled('team_id') && $request->input('team_id') !== $team->id) {
            abort_unless(in_array($request->input('team_id'), TeamScope::for($team)->teamIds, true), 404);
            $filter = Team::query()->findOrFail($request->input('team_id'));
        }

        return response()->json(['data' => $matrix->build($team, $request->user(), $filter)]);
    }
}
