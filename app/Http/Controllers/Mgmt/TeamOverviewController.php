<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Team;
use App\Services\Team\TeamMovePreviewService;
use App\Services\Team\TeamOverviewService;
use App\Services\Team\TeamScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamOverviewController extends Controller
{
    public function show(Request $request, Team $team, TeamOverviewService $overview): JsonResponse
    {
        $this->authorize('view', $team);

        return response()->json(['data' => $overview->build($team, $request->user())]);
    }

    /**
     * What a move to `parent_id` would change. Authorized like the move itself
     * (TeamController::update), see TeamPolicy::move.
     */
    public function movePreview(Request $request, Team $team, TeamMovePreviewService $preview): JsonResponse
    {
        $this->authorize('update', $team);

        $request->validate(['parent_id' => 'nullable|string']);
        $parentId = $request->input('parent_id');

        $parent = $parentId === null ? null : Team::query()->findOrFail($parentId);
        $this->authorize('move', [$team, $parent]);

        if ($parent !== null && in_array($parent->id, TeamScope::for($team)->teamIds, true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'A team cannot move under itself or one of its sub-teams.',
            ]);
        }

        return response()->json(['data' => $preview->preview($team, $parent)]);
    }
}
