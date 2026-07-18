<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamMemberDirectoryService
{
    public function paginate(Team $team, array $params = []): LengthAwarePaginator
    {
        $members = $this->membersForTeam($team)
            ->when($params['role'] ?? null, fn (Collection $collection, string $role) => $collection
                ->filter(fn (User $member) => $member->membership_origin === 'team' && $member->role_key === $this->parseFilterValue($role)['value']))
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
     * @return Collection<int, User>
     */
    private function membersForTeam(Team $team): Collection
    {
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
                $member->setAttribute('space_memberships', []);

                return $member;
            })
            ->keyBy('id');

        $spaceMembershipRows = User::query()
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

        $spaceOnlyMembers = $spaceMembershipRows
            ->reject(fn (Collection $group, string $userId) => $directMembers->has($userId))
            ->map(function (Collection $group) {
                /** @var User $member */
                $member = $group->first();

                $spaceMemberships = $group
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

                $member->setAttribute('role_key', null);
                $member->setAttribute('joined_at', $group
                    ->min(fn (User $row) => Carbon::parse($row->source_joined_at)->toIso8601String()));
                $member->setAttribute('membership_origin', 'space');
                $member->setAttribute('can_assign_team_role', true);
                $member->setAttribute('can_remove', false);
                $member->setAttribute('space_memberships', $spaceMemberships);

                return $member;
            });

        return $directMembers
            ->values()
            ->merge($spaceOnlyMembers->values());
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
