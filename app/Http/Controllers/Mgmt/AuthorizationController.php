<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\RoleResource;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Auth\AuthorizationService;
use App\Services\Auth\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizationController extends Controller
{
    public function __invoke(
        Request $request,
        AuthorizationService $authorizationService,
        RoleService $roleService,
    ): JsonResponse {
        $user = $request->user();
        $team = $request->filled('team_id') ? Team::query()->findOrFail($request->string('team_id')) : null;
        $space = $request->filled('space_id')
            ? Space::query()->with('team')->findOrFail($request->string('space_id'))
            : null;

        if ($team) {
            $this->authorize('view', $team);
        }

        if ($space) {
            $this->authorize('view', $space);
        }

        $contextTeam = $team ?? $space?->team;
        $graph = $authorizationService->graphForUser($user);

        $payload = [
            'user_id' => $user->id,
            'is_root' => $user->is_root,
            'teams' => $request->filled('team_id') || $request->filled('space_id')
                ? []
                : Team::query()
                    ->whereIn('id', $authorizationService->accessibleTeamIds($user))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->toArray(),
            'spaces' => $request->filled('team_id') || $request->filled('space_id')
                ? []
                : Space::query()
                    ->whereIn('id', $authorizationService->accessibleSpaceIds($user))
                    ->orderBy('name')
                    ->get(['id', 'name', 'team_id'])
                    ->toArray(),
            'team' => $team ? [
                'id' => $team->id,
                'role_keys' => $authorizationService->teamRoleKeysForTeam($user, $team),
                'abilities' => $authorizationService->abilitiesForTeam($user, $team),
            ] : null,
            'space' => $space ? [
                'id' => $space->id,
                'team_role_keys' => $authorizationService->teamRoleKeysForSpace($user, $space),
                'space_role_key' => $authorizationService->spaceRoleKeyForSpace($user, $space),
                'abilities' => $authorizationService->abilitiesForSpace($user, $space),
                'plan' => $graph['spaces'][$space->id]['plan'] ?? null,
            ] : null,
            'roles' => [
                'team' => $roleService->teamCatalog()
                    ->map(fn ($role) => (new RoleResource($role))->toArray($request))
                    ->values()
                    ->all(),
                'space' => $roleService->spaceCatalogForTeam($contextTeam)
                    ->map(fn ($role) => (new RoleResource($role))->toArray($request))
                    ->values()
                    ->all(),
            ],
        ];

        return response()->json([
            'data' => $payload,
        ]);
    }
}
