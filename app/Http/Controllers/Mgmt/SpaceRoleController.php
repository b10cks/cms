<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreSpaceRoleRequest;
use App\Http\Requests\Role\UpdateSpaceRoleRequest;
use App\Http\Resources\Management\RoleResource;
use App\Models\Management\Role;
use App\Models\Management\Team;
use App\Services\Auth\AuthorizationService;
use App\Services\Auth\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpaceRoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function index(Team $team): AnonymousResourceCollection
    {
        $this->authorize('manageSpaceRoles', $team);

        return RoleResource::collection($this->roleService->spaceCatalogForTeam($team));
    }

    public function store(Team $team, StoreSpaceRoleRequest $request): JsonResponse
    {
        $this->authorize('manageSpaceRoles', $team);

        $role = $this->roleService->createCustomSpaceRole($team, $request->validated());
        $this->authorizationService->invalidateTeamTree($team);

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(Team $team, Role $role, UpdateSpaceRoleRequest $request): RoleResource
    {
        $this->authorize('manageSpaceRoles', $team);
        abort_unless($role->team_id === $team->id && ! $role->is_system, 404);

        $updatedRole = $this->roleService->updateCustomSpaceRole($role, $request->validated());
        $this->authorizationService->invalidateRole($updatedRole);
        $this->authorizationService->invalidateTeamTree($team);

        return new RoleResource($updatedRole);
    }

    public function destroy(Team $team, Role $role): JsonResponse
    {
        $this->authorize('manageSpaceRoles', $team);
        abort_unless($role->team_id === $team->id && ! $role->is_system, 404);

        $this->roleService->deleteCustomSpaceRole($role);
        $this->authorizationService->invalidateTeamTree($team);

        return response()->json(null, 204);
    }
}
