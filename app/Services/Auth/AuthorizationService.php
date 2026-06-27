<?php

namespace App\Services\Auth;

use App\Models\Management\Role;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthorizationService
{
    public function __construct(
        private readonly RoleService $roleService,
    ) {}

    public function abilitiesForTeam(User $user, Team $team): array
    {
        if ($user->is_root) {
            return config('authorization.team_abilities', []);
        }

        return $this->graphForUser($user)['teams'][$team->id]['abilities'] ?? [];
    }

    public function teamRoleKeysForTeam(User $user, Team $team): array
    {
        if ($user->is_root) {
            return array_keys(config('authorization.roles.team', []));
        }

        return $this->graphForUser($user)['teams'][$team->id]['role_keys'] ?? [];
    }

    public function abilitiesForSpace(User $user, Space $space): array
    {
        if ($user->is_root) {
            return config('authorization.space_abilities', []);
        }

        return $this->graphForUser($user)['spaces'][$space->id]['abilities'] ?? [];
    }

    public function teamRoleKeysForSpace(User $user, Space $space): array
    {
        if ($user->is_root) {
            return array_keys(config('authorization.roles.team', []));
        }

        return $this->graphForUser($user)['spaces'][$space->id]['team_role_keys'] ?? [];
    }

    public function spaceRoleKeyForSpace(User $user, Space $space): ?string
    {
        if ($user->is_root) {
            return null;
        }

        return $this->graphForUser($user)['spaces'][$space->id]['space_role_key'] ?? null;
    }

    public function canInTeam(User $user, Team $team, string $ability): bool
    {
        return $user->is_root || in_array($ability, $this->abilitiesForTeam($user, $team), true);
    }

    public function canInSpace(User $user, Space $space, string $ability): bool
    {
        return $user->is_root || in_array($ability, $this->abilitiesForSpace($user, $space), true);
    }

    public function graphForUser(User $user): array
    {
        if ($user->is_root) {
            return $this->rootGraph($user);
        }

        $cacheKey = $this->cacheKey($user->id);

        return Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('authorization.cache_ttl_seconds', 3600)),
            fn () => $this->buildGraph($user),
        );
    }

    public function accessibleTeamIds(User $user): array
    {
        if ($user->is_root) {
            return DB::table('teams')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
        }

        return array_keys($this->graphForUser($user)['teams'] ?? []);
    }

    public function selectorAccessibleTeamIds(User $user): array
    {
        if ($user->is_root) {
            return DB::table('teams')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
        }

        $teamIds = $this->accessibleTeamIds($user);
        $spaceTeamIds = DB::table('spaces')
            ->whereIn('id', $this->accessibleSpaceIds($user))
            ->whereNotNull('team_id')
            ->pluck('team_id')
            ->all();

        return array_values(array_unique([
            ...$teamIds,
            ...$spaceTeamIds,
        ]));
    }

    public function accessibleSpaceIds(User $user): array
    {
        if ($user->is_root) {
            return DB::table('spaces')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
        }

        return array_keys($this->graphForUser($user)['spaces'] ?? []);
    }

    public function invalidateUser(User|string $user): void
    {
        Cache::forget($this->cacheKey($user instanceof User ? $user->id : $user));
    }

    public function invalidateUsers(iterable $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->invalidateUser((string) $userId);
        }
    }

    public function invalidateRole(Role $role): void
    {
        $userIds = DB::table('team_user')
            ->where('role_id', $role->id)
            ->pluck('user_id')
            ->merge(DB::table('space_user')->where('role_id', $role->id)->pluck('user_id'))
            ->unique()
            ->values();

        $this->invalidateUsers($userIds);
    }

    public function invalidateTeamTree(Team $team): void
    {
        $teamIds = $this->descendantTeamIds($team->id);
        $spaceIds = DB::table('spaces')->whereIn('team_id', $teamIds)->pluck('id');
        $userIds = DB::table('team_user')->whereIn('team_id', $teamIds)->pluck('user_id')
            ->merge(DB::table('space_user')->whereIn('space_id', $spaceIds)->pluck('user_id'))
            ->unique()
            ->values();

        $this->invalidateUsers($userIds);
    }

    /**
     * Invalidate the authorization cache for everyone affected by creating or
     * re-parenting a team. Team roles inherit *downward*, so the members whose
     * resolved access changes are those of the source/destination ancestor
     * chains (they gain/lose the moved subtree), plus the subtree's own members.
     */
    public function invalidateTeamReparent(Team $team, ?string $oldParentId, ?string $newParentId): void
    {
        $teamIds = array_values(array_unique([
            ...$this->descendantTeamIds($team->id),
            ...$this->ancestorTeamIds($oldParentId),
            ...$this->ancestorTeamIds($newParentId),
        ]));

        $spaceIds = DB::table('spaces')->whereIn('team_id', $teamIds)->pluck('id');
        $userIds = DB::table('team_user')->whereIn('team_id', $teamIds)->pluck('user_id')
            ->merge(DB::table('space_user')->whereIn('space_id', $spaceIds)->pluck('user_id'))
            ->unique()
            ->values();

        $this->invalidateUsers($userIds);
    }

    public function invalidateSpace(Space $space): void
    {
        $userIds = DB::table('space_user')
            ->where('space_id', $space->id)
            ->pluck('user_id')
            ->unique()
            ->values();

        $this->invalidateUsers($userIds);

        if ($space->team_id) {
            $this->invalidateTeamTree($space->team()->firstOrFail());
        }
    }

    private function cacheKey(string $userId): string
    {
        return config('authorization.cache_key_prefix', 'authz:user:').$userId.':v1';
    }

    private function rootGraph(User $user): array
    {
        return [
            'user_id' => $user->id,
            'is_root' => true,
            'teams' => [],
            'spaces' => [],
        ];
    }

    private function buildGraph(User $user): array
    {
        $teamMemberships = DB::table('team_user')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->where('team_user.user_id', $user->id)
            ->select('team_user.team_id', 'roles.key', 'roles.abilities')
            ->get();

        $spaceMemberships = DB::table('space_user')
            ->join('roles', 'roles.id', '=', 'space_user.role_id')
            ->where('space_user.user_id', $user->id)
            ->select('space_user.space_id', 'roles.key', 'roles.abilities')
            ->get();

        $teams = DB::table('teams')
            ->select('id', 'parent_id')
            ->whereNull('deleted_at')
            ->get();

        $childrenByParent = $teams->groupBy('parent_id');

        $graph = [
            'user_id' => $user->id,
            'is_root' => false,
            'teams' => [],
            'spaces' => [],
        ];

        $spaceAbilitiesByTeam = [];
        $accessibleTeamIds = [];

        foreach ($teamMemberships as $membership) {
            $descendants = $this->collectDescendantTeamIds($membership->team_id, $childrenByParent);
            $teamAbilities = $this->decodeAbilities($membership->abilities);
            $inheritedSpaceAbilities = config('authorization.team_space_abilities.'.$membership->key, []);

            foreach ($descendants as $teamId) {
                $teamNode = &$graph['teams'][$teamId];
                $teamNode['role_keys'] = array_values(array_unique([
                    ...($teamNode['role_keys'] ?? []),
                    $membership->key,
                ]));
                $teamNode['abilities'] = $this->mergeAbilities($teamNode['abilities'] ?? [], $teamAbilities);

                $spaceAbilitiesByTeam[$teamId] = $this->mergeAbilities(
                    $spaceAbilitiesByTeam[$teamId] ?? [],
                    $inheritedSpaceAbilities,
                );
            }

            $accessibleTeamIds = [...$accessibleTeamIds, ...$descendants];
        }

        $accessibleTeamIds = array_values(array_unique($accessibleTeamIds));

        if ($accessibleTeamIds !== []) {
            $teamSpaces = DB::table('spaces')
                ->select('id', 'team_id')
                ->whereIn('team_id', $accessibleTeamIds)
                ->whereNull('deleted_at')
                ->get();

            foreach ($teamSpaces as $space) {
                $teamRoleKeys = $graph['teams'][$space->team_id]['role_keys'] ?? [];
                $graph['spaces'][$space->id] = [
                    'team_role_keys' => $teamRoleKeys,
                    'space_role_key' => $graph['spaces'][$space->id]['space_role_key'] ?? null,
                    'abilities' => $this->mergeAbilities(
                        $graph['spaces'][$space->id]['abilities'] ?? [],
                        $spaceAbilitiesByTeam[$space->team_id] ?? [],
                    ),
                ];
            }
        }

        foreach ($spaceMemberships as $membership) {
            $spaceNode = &$graph['spaces'][$membership->space_id];
            $spaceNode['team_role_keys'] = $spaceNode['team_role_keys'] ?? [];
            $spaceNode['space_role_key'] = $membership->key;
            $spaceNode['abilities'] = $this->mergeAbilities(
                $spaceNode['abilities'] ?? [],
                $this->decodeAbilities($membership->abilities),
            );
        }

        $spaceIds = array_keys($graph['spaces']);
        if ($spaceIds !== []) {
            $subscriptions = Subscription::query()
                ->with('plan')
                ->whereIn('space_id', $spaceIds)
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('space_id');

            foreach ($spaceIds as $spaceId) {
                $subscription = Space::pickCurrentSubscription($subscriptions->get($spaceId, collect()));
                $graph['spaces'][$spaceId]['plan'] = $subscription ? [
                    'id' => $subscription->plan?->id,
                    'name' => $subscription->plan?->getTranslatedName() ?? $subscription->name,
                    'status' => $subscription->status,
                ] : null;
                $graph['spaces'][$spaceId]['abilities'] = $this->mergeAbilities(
                    [],
                    $graph['spaces'][$spaceId]['abilities'] ?? [],
                );
            }
        }

        foreach ($graph['teams'] as &$teamNode) {
            $teamNode['abilities'] = $this->mergeAbilities([], $teamNode['abilities'] ?? []);
        }

        ksort($graph['teams']);
        ksort($graph['spaces']);

        return $graph;
    }

    private function descendantTeamIds(string $teamId): array
    {
        $teams = DB::table('teams')
            ->select('id', 'parent_id')
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('parent_id');

        return $this->collectDescendantTeamIds($teamId, $teams);
    }

    /**
     * Returns the given team plus all of its ancestors (walking parent_id up to
     * the root). Returns an empty array when $teamId is null.
     *
     * @return array<int, string>
     */
    private function ancestorTeamIds(?string $teamId): array
    {
        if ($teamId === null) {
            return [];
        }

        $teamsById = DB::table('teams')
            ->select('id', 'parent_id')
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $ids = [];
        $current = $teamId;

        while ($current !== null && $teamsById->has($current)) {
            $ids[] = $current;
            $current = $teamsById->get($current)->parent_id;
        }

        return array_values(array_unique($ids));
    }

    private function collectDescendantTeamIds(string $teamId, Collection $childrenByParent): array
    {
        $ids = [$teamId];
        $stack = [$teamId];

        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($childrenByParent->get($current, collect()) as $child) {
                $ids[] = $child->id;
                $stack[] = $child->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function decodeAbilities(string $abilities): array
    {
        /** @var array<int, string> $decoded */
        $decoded = json_decode($abilities, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function mergeAbilities(array $base, array $incoming): array
    {
        $merged = array_values(array_unique([...$base, ...$incoming]));
        sort($merged);

        return $merged;
    }
}
