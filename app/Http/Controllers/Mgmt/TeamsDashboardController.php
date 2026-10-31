<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Team;
use App\Services\Team\TeamsDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamsDashboardController extends Controller
{
    public function __invoke(Request $request, TeamsDashboardService $dashboard): JsonResponse
    {
        $this->authorize('viewAny', Team::class);

        return response()->json(['data' => $dashboard->build($request->user())]);
    }
}
