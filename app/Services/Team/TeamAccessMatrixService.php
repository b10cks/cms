<?php

namespace App\Services\Team;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Auth\MembershipGuard;
use App\Services\Auth\RoleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * People by spaces for a team's subtree. Each cell holds the direct space
 * role, the team role the person inherits there, and whether the actor may
 * change it. MembershipGuard decides editability, so the matrix never offers
 * an edit the member endpoints would refuse.
 */
class TeamAccessMatrixService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly MembershipGuard $guard,
        private readonly RoleService $roles,
    ) {}

    public function build(Team $team, User $actor, ?Team $filter = null): array
    {
        $scope = TeamScope::for($team);
        $filterScope = $filter ? TeamScope::for($filter) : null;
        $spaceIds = $filterScope ? array_values(array_intersect($scope->spaceIds, $filterScope->spaceIds)) : $scope->spaceIds;
        // Rows: everyone holding a team role over these spaces, plus their direct members.
        $rowTeamIds = $filterScope
            ? array_values(array_unique([...$filterScope->teamIds, ...$this->chain($filter->id, $scope)]))
            : [...$scope->teamIds, ...$scope->ancestorIds];

        $teamNames = Team::query()
            ->whereIn('id', [...$scope->teamIds, ...$scope->ancestorIds])
            ->pluck('name', 'id');

        $spaces = Space::query()
            ->with('team')
            ->whereIn('id', $spaceIds)
            ->get()
            ->sortBy(fn (Space $space) => [array_search($space->team_id, $scope->teamIds, true), mb_strtolower($space->name)])
            ->values();

        $teamRows = DB::table('team_user')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->whereIn('team_user.team_id', $rowTeamIds)
            ->get(['team_user.user_id', 'team_user.team_id', 'roles.key', 'roles.name', 'roles.level'])
            ->groupBy('user_id');

        $spaceRows = DB::table('space_user')
            ->join('roles', 'roles.id', '=', 'space_user.role_id')
            ->whereIn('space_user.space_id', $spaceIds)
            ->get(['space_user.user_id', 'space_user.space_id', 'space_user.expires_at', 'roles.key', 'roles.name', 'roles.level'])
            ->groupBy('user_id');

        $users = User::query()
            ->whereIn('id', $teamRows->keys()->merge($spaceRows->keys())->unique())
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get();

        $columns = $spaces->map(fn (Space $space) => $this->column($space, $actor, $scope))->keyBy('id');
        $scopeChain = [$scope->team->id, ...$scope->ancestorIds];

        $people = $users->map(function (User $user) use ($teamRows, $spaceRows, $columns, $scope, $scopeChain, $teamNames) {
            $memberships = $teamRows->get($user->id, collect())->keyBy('team_id');
            $direct = $spaceRows->get($user->id, collect())->keyBy('space_id');

            $cells = [];
            foreach ($columns as $spaceId => $column) {
                $inherited = $this->strongest($memberships, $this->chain($column['team_id'], $scope));
                $own = $direct->get($spaceId);
                [$editable, $reason] = $this->editability($column, $own);

                $cells[$spaceId] = [
                    'direct' => $own ? ['key' => $own->key, 'name' => $own->name] : null,
                    'inherited' => $inherited ? [
                        'key' => $inherited->key,
                        'name' => $inherited->name,
                        'via' => $teamNames->get($inherited->team_id),
                    ] : null,
                    'expires_at' => $own?->expires_at ? Carbon::parse($own->expires_at)->toIso8601String() : null,
                    'editable' => $editable,
                    'reason' => $reason,
                ];
            }

            $teamRole = $this->strongest($memberships, $scopeChain);

            return [
                'id' => $user->id,
                'name' => $user->display_name,
                'email' => $user->email,
                'avatar' => $user->avatar_url,
                'team_role' => $teamRole ? [
                    'key' => $teamRole->key,
                    'name' => $teamRole->name,
                    'via' => $teamNames->get($teamRole->team_id),
                ] : null,
                'cells' => $cells,
            ];
        })->values();

        return [
            'teams' => array_map(fn (string $id) => [
                'id' => $id,
                'name' => $teamNames->get($id),
                'parent_id' => $id === $scope->team->id ? null : $scope->parents[$id],
            ], $scope->teamIds),
            'spaces' => $columns->map(fn (array $column) => collect($column)->except('level')->all())->values()->all(),
            'people' => $people->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(Space $space, User $actor, TeamScope $scope): array
    {
        $canManage = $this->authorization->canInSpace($actor, $space, 'space.members.manage');
        $level = $canManage ? $this->guard->effectiveSpaceLevel($actor, $space) : 0;

        return [
            'id' => $space->id,
            'name' => $space->name,
            'team_id' => $space->team_id,
            'team_name' => $space->team?->name,
            'can_manage' => $canManage,
            'level' => $level,
            'assignable_roles' => $canManage
                ? $this->roles->spaceCatalogForTeam($space->team)
                    ->filter(fn ($role) => $role->level <= $level)
                    ->map(fn ($role) => ['key' => $role->key, 'name' => $role->name])
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function editability(array $column, ?object $direct): array
    {
        if (! $column['can_manage']) {
            return [false, 'no_permission'];
        }

        if ($direct && (int) $direct->level > $column['level']) {
            return [false, 'outranks'];
        }

        return $column['assignable_roles'] === [] ? [false, 'no_permission'] : [true, null];
    }

    /**
     * The team chain a space's team inherits from, nearest first.
     *
     * @return array<int, string>
     */
    private function chain(string $teamId, TeamScope $scope): array
    {
        $chain = [];
        $current = $teamId;
        while ($current !== null && $current !== $scope->team->id && array_key_exists($current, $scope->parents)) {
            $chain[] = $current;
            $current = $scope->parents[$current];
        }

        return [...$chain, $scope->team->id, ...$scope->ancestorIds];
    }

    /**
     * @param  Collection<string, object>  $memberships  Keyed by team id.
     * @param  array<int, string>  $chain  Nearest first; ties go to the nearest team.
     */
    private function strongest(Collection $memberships, array $chain): ?object
    {
        $best = null;
        foreach ($chain as $teamId) {
            $row = $memberships->get($teamId);
            if ($row && (! $best || (int) $row->level > (int) $best->level)) {
                $best = $row;
            }
        }

        return $best;
    }
}
