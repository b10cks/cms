<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamMemberDirectoryService
{
    public function paginate(Team $team, array $params = []): LengthAwarePaginator
    {
        $members = $this->membersForTeam($team)
            ->when($params['role'] ?? null, fn (Collection $collection, string $role) => $collection
                ->filter(fn (User $member) => $member->role_key === $this->parseFilterValue($role)['value']))
            ->when($params['name'] ?? null, fn (Collection $collection, string $name) => $collection
                ->filter(fn (User $member) => $this->matchesTextFilter(
                    "{$member->firstname} {$member->lastname}",
                    $this->parseFilterValue($name),
                )))
            ->when($params['email'] ?? null, fn (Collection $collection, string $email) => $collection
                ->filter(fn (User $member) => $this->matchesTextFilter(
                    (string) $member->email,
                    $this->parseFilterValue($email),
                )))
            ->when($params['isActive'] ?? null, fn (Collection $collection, string $isActive) => $collection
                ->filter(fn (User $member) => $this->matchesBooleanFilter(
                    $member->deleted_at === null,
                    $this->parseFilterValue($isActive),
                )))
            ->when($params['created_at'] ?? null, fn (Collection $collection, string $createdAt) => $collection
                ->filter(fn (User $member) => $this->matchesDateFilter(
                    $member->created_at,
                    $this->parseFilterValue($createdAt),
                )))
            ->when($params['last_login_at'] ?? null, fn (Collection $collection, string $lastLoginAt) => $collection
                ->filter(fn (User $member) => $this->matchesDateFilter(
                    $member->last_login_at,
                    $this->parseFilterValue($lastLoginAt),
                )))
            ->when($params['q'] ?? null, fn (Collection $collection, string $query) => $collection
                ->filter(fn (User $member) => str_contains(
                    mb_strtolower("{$member->firstname} {$member->lastname} {$member->email}"),
                    mb_strtolower($query),
                )));

        [$sortColumn, $sortDirection] = $this->parseSort((string) ($params['sort'] ?? '+firstname'));

        $members = $members
            ->sort(fn (User $left, User $right) => $this->compareMembers($left, $right, $sortColumn, $sortDirection))
            ->values();

        $perPage = max((int) ($params['per_page'] ?? 20), 1);
        $page = max((int) ($params['page'] ?? Paginator::resolveCurrentPage('page')), 1);
        $total = $members->count();
        $items = $members->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    public function findMember(Team $team, string $userId): ?User
    {
        return $this->membersForTeam($team)
            ->first(fn (User $member) => $member->id === $userId);
    }

    /**
     * @return Collection<int, User>
     */
    public function members(Team $team): Collection
    {
        return $this->membersForTeam($team);
    }

    /**
     * Everyone who can act in this team: direct members, members inheriting a
     * role from an ancestor team, and collaborators who only hold a role in one
     * of the team's spaces. Only the direct ones are editable here.
     *
     * @return Collection<int, User>
     */
    private function membersForTeam(Team $team): Collection
    {
        $spaceMembershipRows = $this->spaceMembershipRows($team);
        $spaceMembershipsByUser = $spaceMembershipRows
            ->map(fn (Collection $group) => $this->normalizeSpaceMemberships($group));

        $directMembers = User::query()
            ->withTrashed()
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->leftJoin('roles', 'roles.id', '=', 'team_user.role_id')
            ->where('team_user.team_id', $team->id)
            ->select('users.*', 'roles.key as role_key', 'team_user.created_at as joined_at')
            ->get()
            ->map(function (User $member) {
                $member->setAttribute('membership_origin', 'team');
                $member->setAttribute('can_assign_team_role', true);
                $member->setAttribute('can_remove', true);
                $member->setAttribute('inherited_from', null);
                $member->setAttribute('space_memberships', []);

                return $member;
            })
            ->keyBy('id');

        $inheritedMembers = $this->inheritedMembers($team)
            ->reject(fn (User $member) => $directMembers->has($member->id))
            ->map(function (User $member) use ($spaceMembershipsByUser) {
                $member->setAttribute('membership_origin', 'inherited');
                $member->setAttribute('can_assign_team_role', false);
                $member->setAttribute('can_remove', false);
                $member->setAttribute('inherited_from', [
                    'id' => $member->inherited_team_id,
                    'name' => $member->inherited_team_name,
                ]);
                $member->setAttribute('space_memberships', $spaceMembershipsByUser->get($member->id, []));

                return $member;
            })
            ->keyBy('id');

        $spaceOnlyMembers = $spaceMembershipRows
            ->reject(fn (Collection $group, string $userId) => $directMembers->has($userId) || $inheritedMembers->has($userId))
            ->map(function (Collection $group, string $userId) use ($spaceMembershipsByUser) {
                /** @var User $member */
                $member = $group->first();

                $member->setAttribute('role_key', null);
                $member->setAttribute('joined_at', $group
                    ->min(fn (User $row) => Carbon::parse($row->source_joined_at)->toIso8601String()));
                $member->setAttribute('membership_origin', 'space');
                $member->setAttribute('can_assign_team_role', true);
                $member->setAttribute('can_remove', false);
                $member->setAttribute('inherited_from', null);
                $member->setAttribute('space_memberships', $spaceMembershipsByUser->get($userId, []));

                return $member;
            });

        return $directMembers
            ->values()
            ->merge($inheritedMembers->values())
            ->merge($spaceOnlyMembers->values());
    }

    /**
     * Team roles cascade downward, so a member of an ancestor team holds that
     * role here too without a row in this team's pivot. Each user is reduced to
     * the strongest role they hold up the chain, ties going to the nearest
     * ancestor.
     *
     * @return Collection<int, User>
     */
    private function inheritedMembers(Team $team): Collection
    {
        $ancestorIds = $this->ancestorTeamIds($team);

        if ($ancestorIds === []) {
            return collect();
        }

        $distance = array_flip($ancestorIds);

        return User::query()
            ->withTrashed()
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->leftJoin('roles', 'roles.id', '=', 'team_user.role_id')
            ->whereIn('team_user.team_id', $ancestorIds)
            ->select(
                'users.*',
                'roles.key as role_key',
                'roles.level as role_level',
                'teams.id as inherited_team_id',
                'teams.name as inherited_team_name',
                'team_user.created_at as joined_at',
            )
            ->get()
            ->groupBy('id')
            ->map(fn (Collection $group) => $group
                ->sort(fn (User $left, User $right) => [(int) $right->role_level, $distance[$left->inherited_team_id]]
                    <=> [(int) $left->role_level, $distance[$right->inherited_team_id]])
                ->first())
            ->values();
    }

    /**
     * Ancestor team ids, nearest parent first. Guards against a cycle in
     * `parent_id` rather than spinning forever on corrupt data.
     *
     * @return array<int, string>
     */
    private function ancestorTeamIds(Team $team): array
    {
        $teamsById = DB::table('teams')
            ->select('id', 'parent_id')
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $ids = [];
        $current = $team->parent_id;

        while ($current !== null && $teamsById->has($current) && ! in_array($current, $ids, true)) {
            $ids[] = $current;
            $current = $teamsById->get($current)->parent_id;
        }

        return $ids;
    }

    /**
     * Space membership rows for the team's spaces, grouped by user id.
     *
     * @return Collection<string, Collection<int, User>>
     */
    private function spaceMembershipRows(Team $team): Collection
    {
        return User::query()
            ->withTrashed()
            ->join('space_user', 'space_user.user_id', '=', 'users.id')
            ->join('spaces', 'spaces.id', '=', 'space_user.space_id')
            ->leftJoin('roles', 'roles.id', '=', 'space_user.role_id')
            ->where('spaces.team_id', $team->id)
            ->select(
                'users.*',
                'spaces.id as source_space_id',
                'spaces.name as source_space_name',
                'roles.key as source_space_role_key',
                'space_user.created_at as source_joined_at',
            )
            ->orderBy('spaces.name')
            ->get()
            ->groupBy('id');
    }

    /**
     * @param  Collection<int, User>  $group
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSpaceMemberships(Collection $group): array
    {
        return $group
            ->map(fn (User $row) => [
                'space' => [
                    'id' => $row->source_space_id,
                    'name' => $row->source_space_name,
                ],
                'role' => $row->source_space_role_key,
                'joined_at' => Carbon::parse($row->source_joined_at)->toIso8601String(),
            ])
            ->sortBy(fn (array $membership) => mb_strtolower((string) $membership['space']['name']))
            ->values()
            ->all();
    }

    /**
     * @return array{operator: string, value: string}
     */
    private function parseFilterValue(string $filter): array
    {
        if (! str_contains($filter, ':')) {
            return [
                'operator' => 'eq',
                'value' => $filter,
            ];
        }

        [$operator, $value] = explode(':', $filter, 2);

        return [
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function matchesTextFilter(string $haystack, array $filter): bool
    {
        $haystack = mb_strtolower($haystack);
        $needle = mb_strtolower($filter['value']);

        return match ($filter['operator']) {
            '^like' => str_starts_with($haystack, $needle),
            'like$' => str_ends_with($haystack, $needle),
            'neq' => $haystack !== $needle,
            'eq' => $haystack === $needle,
            '!like' => ! str_contains($haystack, $needle),
            'like' => str_contains($haystack, $needle),
            default => str_contains($haystack, $needle),
        };
    }

    private function matchesBooleanFilter(bool $value, array $filter): bool
    {
        $expected = filter_var($filter['value'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $expected === null ? true : $value === $expected;
    }

    private function matchesDateFilter(mixed $value, array $filter): bool
    {
        if (! $value instanceof Carbon && $value !== null) {
            $value = Carbon::parse($value);
        }

        if (! $value instanceof Carbon) {
            return false;
        }

        $target = Carbon::parse($filter['value']);

        return match ($filter['operator']) {
            'gt' => $value->gt($target),
            'gte' => $value->gte($target),
            'lt' => $value->lt($target),
            'lte' => $value->lte($target),
            'eq' => $value->equalTo($target),
            default => $value->equalTo($target),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseSort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '+-');

        return [$column, $direction];
    }

    private function compareMembers(User $left, User $right, string $column, string $direction): int
    {
        $multiplier = $direction === 'desc' ? -1 : 1;
        $leftValue = $this->sortValue($left, $column);
        $rightValue = $this->sortValue($right, $column);

        if ($leftValue === $rightValue) {
            return $multiplier * strnatcasecmp($left->name, $right->name);
        }

        if ($leftValue === null) {
            return 1;
        }

        if ($rightValue === null) {
            return -1;
        }

        return $multiplier * ($leftValue <=> $rightValue);
    }

    private function sortValue(User $member, string $column): string|int|null
    {
        return match ($column) {
            'lastname' => mb_strtolower((string) $member->lastname),
            'email' => mb_strtolower((string) $member->email),
            'created_at' => $member->created_at?->getTimestamp(),
            'last_login_at' => $member->last_login_at?->getTimestamp(),
            default => mb_strtolower((string) $member->firstname),
        };
    }
}
