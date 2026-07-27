<?php

namespace App\Services\Space;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class SpaceMemberDirectoryService
{
    public function paginate(Space $space, array $params = []): LengthAwarePaginator
    {
        $query = $this->memberQuery($space)
            ->when($params['role'] ?? null, fn (Builder $query, string $role) => $query
                ->where('roles.key', $this->parseFilterValue($role)['value']))
            ->when($params['name'] ?? null, fn (Builder $query, string $name) => $this->applyTextFilter(
                $query,
                $this->nameExpression($query),
                $this->parseFilterValue($name),
            ))
            ->when($params['email'] ?? null, fn (Builder $query, string $email) => $this->applyTextFilter(
                $query,
                'users.email',
                $this->parseFilterValue($email),
            ));

        [$sortColumn, $sortDirection] = $this->parseSort((string) ($params['sort'] ?? '+firstname'));
        $this->applySort($query, $sortColumn, $sortDirection);

        $perPage = max((int) ($params['per_page'] ?? 20), 1);
        $page = max((int) ($params['page'] ?? Paginator::resolveCurrentPage('page')), 1);

        $members = $query->paginate($perPage, ['*'], 'page', $page);
        $members->getCollection()->transform(fn (User $member) => $this->decorate($member));

        return $members;
    }

    public function findMember(Space $space, string $userId): ?User
    {
        $member = $this->memberQuery($space)
            ->where('users.id', $userId)
            ->first();

        return $member ? $this->decorate($member) : null;
    }

    /**
     * @return Collection<int, User>
     */
    public function members(Space $space): Collection
    {
        return $this->memberQuery($space)
            ->get()
            ->map(fn (User $member) => $this->decorate($member));
    }

    private function memberQuery(Space $space): Builder
    {
        return User::query()
            ->withTrashed()
            ->join('space_user', 'space_user.user_id', '=', 'users.id')
            ->leftJoin('roles', 'roles.id', '=', 'space_user.role_id')
            ->where('space_user.space_id', $space->id)
            ->select('users.*', 'roles.key as role_key', 'space_user.created_at as joined_at');
    }

    private function decorate(User $member): User
    {
        $member->setAttribute('can_assign_space_role', true);
        $member->setAttribute('can_remove', true);

        return $member;
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

    private function nameExpression(Builder $query): string
    {
        $firstname = "COALESCE(users.firstname, '')";
        $lastname = "COALESCE(users.lastname, '')";

        return $query->getConnection()->getDriverName() === 'sqlite'
            ? "{$firstname} || ' ' || {$lastname}"
            : "CONCAT({$firstname}, ' ', {$lastname})";
    }

    /**
     * Case-insensitive text matching in SQL, mirroring the operators the
     * directory previously applied in PHP.
     *
     * @param  array{operator: string, value: string}  $filter
     */
    private function applyTextFilter(Builder $query, string $expression, array $filter): Builder
    {
        $needle = mb_strtolower($filter['value']);
        $escaped = addcslashes($needle, '\\%_');

        return match ($filter['operator']) {
            '^like' => $query->whereRaw("LOWER({$expression}) LIKE ?", ["{$escaped}%"]),
            'like$' => $query->whereRaw("LOWER({$expression}) LIKE ?", ["%{$escaped}"]),
            'neq' => $query->whereRaw("LOWER({$expression}) != ?", [$needle]),
            'eq' => $query->whereRaw("LOWER({$expression}) = ?", [$needle]),
            '!like' => $query->whereRaw("LOWER({$expression}) NOT LIKE ?", ["%{$escaped}%"]),
            default => $query->whereRaw("LOWER({$expression}) LIKE ?", ["%{$escaped}%"]),
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

    private function applySort(Builder $query, string $column, string $direction): void
    {
        $expression = match ($column) {
            'lastname' => 'LOWER(users.lastname)',
            'email' => 'LOWER(users.email)',
            'created_at' => 'users.created_at',
            'last_login_at' => 'users.last_login_at',
            default => 'LOWER(users.firstname)',
        };

        $direction = $direction === 'desc' ? 'desc' : 'asc';

        // Nulls always sort last (as the previous PHP comparator did), ties
        // break on the member's name.
        $query
            ->orderByRaw("{$expression} IS NULL")
            ->orderByRaw("{$expression} {$direction}")
            ->orderByRaw("LOWER(users.firstname) {$direction}")
            ->orderByRaw("LOWER(users.lastname) {$direction}");
    }
}
