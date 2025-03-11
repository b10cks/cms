<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Team;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TeamUserController extends Controller
{
    public function __construct(private TeamService $teamService)
    {
    }

    /**
     * Attach user to team
     */
    public function store(Request $request, Team $team): Response|JsonResponse
    {
        $this->authorize('update', $team);

        $request->validate([
            'user_id' => 'required|string|exists:users,id',
            'role' => 'nullable|string|max:50'
        ]);

        try {
            $this->teamService->attachUser(
                $team,
                $request->user_id,
                $request->role
            );

            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to attach user to team.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Update user role in team
     */
    public function update(Request $request, Team $team, string $userId): Response|JsonResponse
    {
        $this->authorize('update', $team);

        $request->validate([
            'role' => 'nullable|string|max:50'
        ]);

        try {
            $this->teamService->updateUserRole($team, $userId, $request->role);
            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update user role.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Detach user from team
     */
    public function destroy(Team $team, string $userId): Response|JsonResponse
    {
        $this->authorize('update', $team);

        try {
            $this->teamService->detachUser($team, $userId);
            return response()->noContent();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to detach user from team.',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
