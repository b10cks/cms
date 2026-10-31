<?php

namespace App\Services\Team;

use App\Enums\RoleScope;
use App\Models\Management\Role;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\RoleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What moving a team under a new parent changes. Team roles cascade
 * downward and custom space roles are looked up through the ancestors, so a
 * move changes who inherits access to the subtree and which custom roles
 * apply in it. Direct space memberships are untouched.
 */
class TeamMovePreviewService
{
    public function __construct(private readonly RoleService $roles) {}

    public function preview(Team $team, ?Team $newParent): array
    {
        $scope = TeamScope::for($team);
        $oldChain = $scope->ancestorIds;
        $newChain = $newParent ? $this->roles->ancestorTeamIds($newParent) : [];

        $before = $this->inheritedRoles($oldChain);
        $after = $this->inheritedRoles($newChain);

        $gains = $after->diffKeys($before)->values();
        $losses = $before->diffKeys($after)->values();
        $changes = $after
            ->intersectByKeys($before)
            ->filter(fn (array $entry, string $userId) => $entry['role'] !== $before[$userId]['role'])
            ->map(fn (array $entry, string $userId) => [
                ...$entry,
                'previous_role' => $before[$userId]['role'],
                'previous_role_name' => $before[$userId]['role_name'],
            ])
            ->values();

        $oldRoles = $this->customRoles($oldChain);
        $newRoles = $this->customRoles($newChain);
        $stopping = $oldRoles->diffKeys($newRoles);

        return [
            'from' => $team->parent_id ? ['id' => $team->parent_id, 'name' => $team->parent?->name] : null,
            'to' => $newParent ? ['id' => $newParent->id, 'name' => $newParent->name] : null,
            'gains' => $gains->all(),
            'losses' => $losses->all(),
            'changes' => $changes->all(),
            'roles_starting' => $newRoles->diffKeys($oldRoles)->map(fn (Role $role) => $this->role($role))->values()->all(),
            'roles_stopping' => $stopping->map(fn (Role $role) => [
                ...$this->role($role),
                'assignments' => DB::table('space_user')
                    ->where('role_id', $role->id)
                    ->whereIn('space_id', $scope->spaceIds)
                    ->count(),
            ])->values()->all(),
            'direct_space_roles' => DB::table('space_user')->whereIn('space_id', $scope->spaceIds)->count(),
        ];
    }

    /**
     * Strongest team role per user along the chain, nearest team winning ties.
     *
     * @param  array<int, string>  $chain  Nearest first.
     * @return Collection<string, array<string, mixed>>
     */
    private function inheritedRoles(array $chain): Collection
    {
        if ($chain === []) {
            return collect();
        }

        $distance = array_flip($chain);

        return User::query()
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->whereIn('team_user.team_id', $chain)
            ->select('users.*', 'roles.key as role_key', 'roles.name as role_name', 'roles.level as role_level', 'teams.id as via_id', 'teams.name as via_name')
            ->get()
            ->groupBy('id')
            ->map(fn (Collection $rows) => $rows
                ->sort(fn (User $a, User $b) => [(int) $b->role_level, $distance[$a->via_id]] <=> [(int) $a->role_level, $distance[$b->via_id]])
                ->first())
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar_url,
                'role' => $user->role_key,
                'role_name' => $user->role_name,
                'via' => ['id' => $user->via_id, 'name' => $user->via_name],
            ]);
    }

    /**
     * Custom space roles visible from the chain, by key; the nearest team's
     * definition wins, as in RoleService::resolveSpaceRole.
     *
     * @param  array<int, string>  $chain
     * @return Collection<string, Role>
     */
    private function customRoles(array $chain): Collection
    {
        if ($chain === []) {
            return collect();
        }

        $distance = array_flip($chain);

        return Role::query()
            ->with('team:id,name')
            ->where('scope', RoleScope::SPACE)
            ->whereIn('team_id', $chain)
            ->get()
            ->sortBy(fn (Role $role) => $distance[$role->team_id])
            ->unique('key')
            ->keyBy('key');
    }

    private function role(Role $role): array
    {
        return [
            'key' => $role->key,
            'name' => $role->name,
            'team' => ['id' => $role->team_id, 'name' => $role->team?->name],
        ];
    }
}
