<?php

namespace App\Services\Auth;

use App\Enums\RoleScope;
use App\Enums\SpaceRoleKey;
use App\Enums\TeamRoleKey;
use App\Models\Management\Role;
use App\Models\Management\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class RoleService
{
    public function teamCatalog(): Collection
    {
        return Role::query()
            ->where('scope', RoleScope::TEAM)
            ->whereNull('team_id')
            ->orderByDesc('level')
            ->get();
    }

    public function mutableSpaceCatalog(Team $team): Collection
    {
        return Role::query()
            ->where('scope', RoleScope::SPACE)
            ->where('team_id', $team->id)
            ->orderByDesc('level')
            ->orderBy('name')
            ->get();
    }

    public function spaceCatalogForTeam(?Team $team = null): Collection
    {
        $systemRoles = Role::query()
            ->where('scope', RoleScope::SPACE)
            ->whereNull('team_id')
            ->orderByDesc('level')
            ->get()
            ->keyBy('key');

        if (! $team) {
            return $systemRoles->values();
        }

        $teamIds = $this->ancestorTeamIds($team);
        $customRoles = Role::query()
            ->where('scope', RoleScope::SPACE)
            ->whereIn('team_id', $teamIds)
            ->orderByDesc('level')
            ->get();

        $catalog = collect();
        foreach ($customRoles->sortBy(fn (Role $role) => array_search($role->team_id, $teamIds, true)) as $role) {
            if (! $catalog->has($role->key) && ! $systemRoles->has($role->key)) {
                $catalog->put($role->key, $role);
            }
        }

        return $systemRoles->union($catalog)->values();
    }

    public function resolveTeamRole(string $key): Role
    {
        return $this->resolveRole(RoleScope::TEAM, $key);
    }

    public function resolveSpaceRole(string $key, ?Team $team = null): Role
    {
        return $this->resolveRole(RoleScope::SPACE, $key, $team);
    }

    public function resolveRole(RoleScope $scope, string $key, ?Team $team = null): Role
    {
        $systemRole = Role::query()
            ->where('scope', $scope)
            ->whereNull('team_id')
            ->where('key', $key)
            ->first();

        if ($systemRole) {
            return $systemRole;
        }

        if (! $team || $scope !== RoleScope::SPACE) {
            throw ValidationException::withMessages([
                'role' => ['The selected role is invalid for this context.'],
            ]);
        }

        $teamIds = $this->ancestorTeamIds($team);
        $customRole = Role::query()
            ->where('scope', $scope)
            ->whereIn('team_id', $teamIds)
            ->where('key', $key)
            ->get()
            ->sortBy(fn (Role $role) => array_search($role->team_id, $teamIds, true))
            ->first();

        if ($customRole) {
            return $customRole;
        }

        throw ValidationException::withMessages([
            'role' => ['The selected role is invalid for this context.'],
        ]);
    }

    public function validateTeamRoleKey(string $key): string
    {
        if (! in_array($key, TeamRoleKey::values(), true)) {
            throw ValidationException::withMessages([
                'role' => ['The selected role is invalid for this team.'],
            ]);
        }

        return $key;
    }

    public function validateSpaceRoleKey(string $key, ?Team $team = null): string
    {
        $this->resolveSpaceRole($key, $team);

        return $key;
    }

    public function normalizeSpaceAbilities(array $abilities): array
    {
        $allowedAbilities = config('authorization.space_abilities', []);
        $normalized = array_values(array_unique(array_filter($abilities, 'is_string')));
        $invalid = array_values(array_diff($normalized, $allowedAbilities));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'abilities' => ['Unknown abilities: '.implode(', ', $invalid)],
            ]);
        }

        sort($normalized);

        return $normalized;
    }

    public function createCustomSpaceRole(Team $team, array $attributes): Role
    {
        $key = strtolower((string) ($attributes['key'] ?? ''));
        $this->ensureCustomSpaceRoleKeyIsAvailable($team, $key);

        return Role::query()->create([
            'team_id' => $team->id,
            'scope' => RoleScope::SPACE,
            'key' => $key,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'level' => (int) $attributes['level'],
            'is_system' => false,
            'abilities' => $this->normalizeSpaceAbilities($attributes['abilities'] ?? []),
        ]);
    }

    public function updateCustomSpaceRole(Role $role, array $attributes): Role
    {
        if ($role->is_system || $role->scope !== RoleScope::SPACE || ! $role->team_id) {
            throw ValidationException::withMessages([
                'role' => ['Only custom space roles can be updated.'],
            ]);
        }

        $key = strtolower((string) ($attributes['key'] ?? $role->key));
        if ($key !== $role->key) {
            $this->ensureCustomSpaceRoleKeyIsAvailable($role->team, $key, $role);
        }

        $role->fill([
            'key' => $key,
            'name' => $attributes['name'] ?? $role->name,
            'description' => Arr::get($attributes, 'description', $role->description),
            'level' => (int) ($attributes['level'] ?? $role->level),
            'abilities' => $this->normalizeSpaceAbilities($attributes['abilities'] ?? $role->abilities),
        ]);
        $role->save();

        return $role->refresh();
    }

    public function deleteCustomSpaceRole(Role $role): void
    {
        if ($role->is_system || $role->scope !== RoleScope::SPACE || ! $role->team_id) {
            throw ValidationException::withMessages([
                'role' => ['Only custom space roles can be deleted.'],
            ]);
        }

        $isAssigned = \DB::table('space_user')->where('role_id', $role->id)->exists();
        $hasInvites = \DB::table('invites')->where('role_id', $role->id)->exists();

        if ($isAssigned || $hasInvites) {
            throw ValidationException::withMessages([
                'role' => ['This role cannot be deleted while assignments or invites still reference it.'],
            ]);
        }

        $role->delete();
    }

    public function ancestorTeamIds(Team $team): array
    {
        $ids = [];
        $current = $team->withoutRelations();

        while ($current) {
            $ids[] = $current->id;
            if (! $current->parent_id) {
                break;
            }

            $current = Team::query()->find($current->parent_id);
        }

        return $ids;
    }

    private function ensureCustomSpaceRoleKeyIsAvailable(Team $team, string $key, ?Role $ignoreRole = null): void
    {
        if ($key === '' || ! preg_match('/^[a-z0-9_-]+$/', $key)) {
            throw ValidationException::withMessages([
                'key' => ['Role keys may only contain lowercase letters, numbers, dashes, and underscores.'],
            ]);
        }

        if (in_array($key, SpaceRoleKey::values(), true)) {
            throw ValidationException::withMessages([
                'key' => ['Custom roles may not reuse a built-in space role key.'],
            ]);
        }

        $query = Role::query()
            ->where('scope', RoleScope::SPACE)
            ->where('team_id', $team->id)
            ->where('key', $key);

        if ($ignoreRole) {
            $query->whereKeyNot($ignoreRole->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'key' => ['A custom role with this key already exists for the team.'],
            ]);
        }
    }
}
