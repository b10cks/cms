<?php

namespace App\Services\People;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Space\SpaceMemberDirectoryService;
use App\Services\Team\TeamMemberDirectoryService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a unified, paginated "people" directory that merges accepted members
 * with their still-outstanding (pending/expired) invites into a single list.
 *
 * Members and invites are assembled and paginated in memory, mirroring the
 * existing member directory services — no SQL union is involved.
 */
class PeopleDirectoryService
{
    public function __construct(
        private readonly SpaceMemberDirectoryService $spaceMembers,
        private readonly TeamMemberDirectoryService $teamMembers,
    ) {}

    /**
     * @return array{data: LengthAwarePaginator, counts: array{members: int, pending: int, total: int}}
     */
    public function paginateForSpace(Space $space, array $params = []): array
    {
        $members = $this->spaceMembers->members($space)
            ->map(fn (User $member) => $this->normalizeMember($member, 'space'));

        $invites = $this->invitesFor('space_id', $space->id)
            ->map(fn (Invite $invite) => $this->normalizeInvite($invite));

        return $this->assemble($members->concat($invites), $params);
    }

    /**
     * @return array{data: LengthAwarePaginator, counts: array{members: int, pending: int, total: int}}
     */
    public function paginateForTeam(Team $team, array $params = []): array
    {
        $members = $this->teamMembers->members($team)
            ->map(fn (User $member) => $this->normalizeMember($member, 'team'));

        $invites = $this->invitesFor('team_id', $team->id)
            ->map(fn (Invite $invite) => $this->normalizeInvite($invite));

        return $this->assemble($members->concat($invites), $params);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{data: LengthAwarePaginator, counts: array{members: int, pending: int, total: int}}
     */
    private function assemble(Collection $rows, array $params): array
    {
        $rows = $rows
            ->when($params['role'] ?? null, fn (Collection $collection, string $role) => $collection
                ->filter(fn (array $row) => $row['role'] === $this->parseFilterValue($role)['value']))
            ->when($params['name'] ?? null, fn (Collection $collection, string $name) => $collection
                ->filter(fn (array $row) => $this->matchesTextFilter(
                    (string) $row['sort_name'],
                    $this->parseFilterValue($name),
                )))
            ->when($params['email'] ?? null, fn (Collection $collection, string $email) => $collection
                ->filter(fn (array $row) => $this->matchesTextFilter(
                    (string) $row['email'],
                    $this->parseFilterValue($email),
                )));

        $counts = [
            'members' => $rows->where('kind', 'member')->count(),
            'pending' => $rows->where('kind', 'invite')->count(),
        ];
        $counts['total'] = $counts['members'] + $counts['pending'];

        $rows = match ($params['segment'] ?? 'all') {
            'members' => $rows->where('kind', 'member'),
            'pending' => $rows->where('kind', 'invite'),
            default => $rows,
        };

        [$sortColumn, $sortDirection] = $this->parseSort((string) ($params['sort'] ?? '+firstname'));

        $rows = $rows
            ->sort(fn (array $left, array $right) => $this->compareRows($left, $right, $sortColumn, $sortDirection))
            ->values();

        $perPage = max((int) ($params['per_page'] ?? 20), 1);
        $page = max((int) ($params['page'] ?? Paginator::resolveCurrentPage('page')), 1);
        $total = $rows->count();
        $items = $rows->forPage($page, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );

        return [
            'data' => $paginator,
            'counts' => $counts,
        ];
    }

    /**
     * @return Collection<int, Invite>
     */
    private function invitesFor(string $column, string $id): Collection
    {
        return Invite::query()
            ->leftJoin('roles', 'roles.id', '=', 'invites.role_id')
            ->where("invites.{$column}", $id)
            ->whereNull('invites.accepted_at')
            ->whereNull('invites.declined_at')
            ->select('invites.*', 'roles.key as role_key')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMember(User $member, string $scope): array
    {
        $joinedAt = $member->joined_at ? Carbon::parse($member->joined_at) : null;

        return [
            'kind' => 'member',
            'id' => $member->id,
            'user_id' => $member->id,
            'invite_id' => null,
            'user' => [
                'id' => $member->id,
                'firstname' => $member->firstname,
                'lastname' => $member->lastname,
                'name' => $member->name,
                'email' => $member->email,
                'avatar' => $member->avatar_url,
                'initials' => $member->initials,
            ],
            'email' => $member->email,
            'role' => $member->role_key ?? null,
            'state' => 'active',
            'can_assign_role' => (bool) ($scope === 'team'
                ? ($member->can_assign_team_role ?? true)
                : ($member->can_assign_space_role ?? true)),
            'can_remove' => (bool) ($member->can_remove ?? true),
            'membership_origin' => $scope === 'team' ? ($member->membership_origin ?? 'team') : null,
            'space_memberships' => $scope === 'team' ? ($member->space_memberships ?? []) : [],
            'joined_at' => $joinedAt?->toIso8601String(),
            'invited_at' => null,
            'expires_at' => null,
            'created_at' => $member->created_at?->toIso8601String(),
            'sort_name' => $member->name,
            'sort_date' => $joinedAt?->getTimestamp() ?? $member->created_at?->getTimestamp(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeInvite(Invite $invite): array
    {
        return [
            'kind' => 'invite',
            'id' => $invite->id,
            'user_id' => null,
            'invite_id' => $invite->id,
            'user' => null,
            'email' => $invite->email,
            'role' => $invite->role,
            'state' => $invite->isExpired() ? 'expired' : 'pending',
            'can_assign_role' => false,
            'can_remove' => false,
            'membership_origin' => null,
            'space_memberships' => [],
            'joined_at' => null,
            'invited_at' => $invite->created_at?->toIso8601String(),
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'created_at' => $invite->created_at?->toIso8601String(),
            'sort_name' => $invite->email,
            'sort_date' => $invite->created_at?->getTimestamp(),
        ];
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

    /**
     * @return array{0: string, 1: string}
     */
    private function parseSort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '+-');

        return [$column, $direction];
    }

    private function compareRows(array $left, array $right, string $column, string $direction): int
    {
        $multiplier = $direction === 'desc' ? -1 : 1;
        $leftValue = $this->sortValue($left, $column);
        $rightValue = $this->sortValue($right, $column);

        if ($leftValue === $rightValue) {
            return $multiplier * strnatcasecmp((string) $left['sort_name'], (string) $right['sort_name']);
        }

        if ($leftValue === null) {
            return 1;
        }

        if ($rightValue === null) {
            return -1;
        }

        return $multiplier * ($leftValue <=> $rightValue);
    }

    private function sortValue(array $row, string $column): string|int|null
    {
        return match ($column) {
            'lastname' => mb_strtolower((string) ($row['user']['lastname'] ?? $row['email'])),
            'email' => mb_strtolower((string) $row['email']),
            'role' => mb_strtolower((string) ($row['role'] ?? '')),
            'joined_at', 'created_at' => $row['sort_date'],
            default => mb_strtolower((string) $row['sort_name']),
        };
    }
}
