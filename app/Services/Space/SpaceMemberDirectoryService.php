<?php

namespace App\Services\Space;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SpaceMemberDirectoryService
{
    public function paginate(Space $space, array $params = []): LengthAwarePaginator
    {
        $members = $this->membersForSpace($space)
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

    public function findMember(Space $space, string $userId): ?User
    {
        return $this->membersForSpace($space)
            ->first(fn (User $member) => $member->id === $userId);
    }

    /**
     * @return Collection<int, User>
     */
    public function members(Space $space): Collection
    {
        return $this->membersForSpace($space);
    }

    /**
     * @return Collection<int, User>
     */
    private function membersForSpace(Space $space): Collection
    {
        return User::query()
            ->withTrashed()
            ->join('space_user', 'space_user.user_id', '=', 'users.id')
            ->leftJoin('roles', 'roles.id', '=', 'space_user.role_id')
            ->where('space_user.space_id', $space->id)
            ->select('users.*', 'roles.key as role_key', 'space_user.created_at as joined_at')
            ->get()
            ->map(function (User $member) {
                $member->setAttribute('can_assign_space_role', true);
                $member->setAttribute('can_remove', true);

                return $member;
            });
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
