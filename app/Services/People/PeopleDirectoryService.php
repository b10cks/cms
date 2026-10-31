<?php

namespace App\Services\People;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\RoleService;
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
    /** Members whose access ends within this many days make up the "expiring" segment. */
    public const EXPIRING_DAYS = 14;

    public function __construct(
        private readonly SpaceMemberDirectoryService $spaceMembers,
        private readonly TeamMemberDirectoryService $teamMembers,
        private readonly RoleService $roles,
    ) {}

    /**
     * @return array{data: LengthAwarePaginator, counts: array{members: int, pending: int, total: int}}
     */
    public function paginateForSpace(Space $space, array $params = []): array
    {
        $inherited = $this->inheritedTeamRoles($space);

        $direct = $this->spaceMembers->members($space)
            ->map(fn (User $member) => [
                ...$this->normalizeMember($member, 'space'),
                'membership_origin' => 'space',
                'inherited_from' => $inherited->get($member->id)?->via,
                'inherited_role' => $inherited->get($member->id)?->role_key,
            ]);

        // Team roles reach every space of the team, so those people have
        // access here without a row of their own. Listed, not editable here.
        $members = $direct->concat($inherited
            ->reject(fn (object $row) => $direct->contains('user_id', $row->user->id))
            ->map(fn (object $row) => [
                ...$this->normalizeMember($row->user, 'space'),
                'role' => null,
                'can_assign_role' => false,
                'can_remove' => false,
                'membership_origin' => 'inherited',
                'inherited_from' => $row->via,
                'inherited_role' => $row->role_key,
            ])
            ->values());

        $invites = $this->invitesFor('space_id', $space->id)
            ->map(fn (Invite $invite) => $this->normalizeInvite($invite));

        return $this->assemble($members->concat($invites), $params);
    }

    /**
     * Strongest team role per user over the space's team chain, nearest team
     * winning ties.
     *
     * @return Collection<string, object{user: User, role_key: string, via: array{id: string, name: string}}>
     */
    private function inheritedTeamRoles(Space $space): Collection
    {
        if (! $space->team) {
            return collect();
        }

        $chain = $this->roles->ancestorTeamIds($space->team);
        $distance = array_flip($chain);

        return User::query()
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->whereIn('team_user.team_id', $chain)
            ->select(
                'users.*',
                'roles.key as role_key',
                'roles.level as role_level',
                'teams.id as via_team_id',
                'teams.name as via_team_name',
                'team_user.created_at as joined_at',
                'team_user.source as membership_source',
            )
            ->get()
            ->groupBy('id')
            ->map(function (Collection $rows) use ($distance) {
                /** @var User $user */
                $user = $rows
                    ->sort(fn (User $a, User $b) => [(int) $b->role_level, $distance[$a->via_team_id]]
                        <=> [(int) $a->role_level, $distance[$b->via_team_id]])
                    ->first();

                return (object) [
                    'user' => $user,
                    'role_key' => $user->role_key,
                    'via' => ['id' => $user->via_team_id, 'name' => $user->via_team_name],
                ];
            });
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

        $expiringBefore = now()->addDays(self::EXPIRING_DAYS);
        $isExpiring = fn (array $row) => $row['access_expires_at'] !== null
            && Carbon::parse($row['access_expires_at'])->lte($expiringBefore);

        $counts = [
            'members' => $rows->where('kind', 'member')->count(),
            'pending' => $rows->where('kind', 'invite')->count(),
            'expiring' => $rows->filter($isExpiring)->count(),
        ];
        $counts['total'] = $counts['members'] + $counts['pending'];

        $rows = match ($params['segment'] ?? 'all') {
            'members' => $rows->where('kind', 'member'),
            'pending' => $rows->where('kind', 'invite'),
            'expiring' => $rows->filter($isExpiring),
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
        // Pivot columns only some directory queries select.
        $extra = $member->getAttributes();

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
            'inherited_from' => $scope === 'team' ? ($member->inherited_from ?? null) : null,
            'inherited_role' => null,
            'space_memberships' => $scope === 'team' ? ($member->space_memberships ?? []) : [],
            'source' => $extra['membership_source'] ?? null,
            'last_active_at' => $member->last_login_at?->toIso8601String(),
            'access_expires_at' => isset($extra['access_expires_at'])
                ? Carbon::parse($extra['access_expires_at'])->toIso8601String()
                : null,
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
            'inherited_from' => null,
            'inherited_role' => null,
            'space_memberships' => [],
            'source' => 'invite',
            'last_active_at' => null,
            'access_expires_at' => $invite->access_expires_at?->toIso8601String(),
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
