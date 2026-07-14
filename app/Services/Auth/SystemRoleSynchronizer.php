<?php

namespace App\Services\Auth;

use App\Models\Management\Role;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles the system roles in the database with the definitions in
 * config/authorization.php. Safe to run repeatedly: it only touches rows whose
 * definition actually drifted, and leaves custom (team-owned) roles alone.
 */
class SystemRoleSynchronizer
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const RESTORED = 'restored';

    public const PRUNED = 'pruned';

    public const IN_USE = 'in_use';

    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * @return array<int, array{scope: string, key: string, status: string, changes: array<int, string>}>
     */
    public function sync(bool $prune = false, bool $dryRun = false): array
    {
        $results = [];

        foreach (config('authorization.roles', []) as $scope => $roles) {
            foreach ($roles as $key => $definition) {
                $results[] = $this->syncRole((string) $scope, (string) $key, $definition, $dryRun);
            }
        }

        if ($prune) {
            $results = [...$results, ...$this->pruneRemovedRoles($dryRun)];
        }

        return $results;
    }

    /**
     * @param  array{name: string, description: string|null, level: int, abilities: array<int, string>}  $definition
     * @return array{scope: string, key: string, status: string, changes: array<int, string>}
     */
    private function syncRole(string $scope, string $key, array $definition, bool $dryRun): array
    {
        $role = Role::withTrashed()
            ->where('scope', $scope)
            ->whereNull('team_id')
            ->where('key', $key)
            ->first();

        $attributes = [
            'name' => $definition['name'],
            'description' => $definition['description'] ?? null,
            'level' => (int) $definition['level'],
            'is_system' => true,
            'abilities' => $this->normalizeAbilities($definition['abilities'] ?? []),
        ];

        if (! $role) {
            if (! $dryRun) {
                Role::query()->create([
                    'team_id' => null,
                    'scope' => $scope,
                    'key' => $key,
                    ...$attributes,
                ]);
            }

            return $this->result($scope, $key, self::CREATED, array_keys($attributes));
        }

        $changes = $this->diff($role, $attributes);
        $wasTrashed = $role->trashed();

        if ($changes === [] && ! $wasTrashed) {
            return $this->result($scope, $key, self::UNCHANGED, []);
        }

        if (! $dryRun) {
            $role->fill($attributes);
            $role->deleted_at = null;
            $role->save();

            // Abilities are cached per user in the authorization graph, so the
            // change is invisible until the members holding this role are flushed.
            $this->authorizationService->invalidateRole($role);
        }

        return $this->result(
            $scope,
            $key,
            $wasTrashed ? self::RESTORED : self::UPDATED,
            $changes,
        );
    }

    /**
     * Soft-deletes system roles that no longer exist in config. Roles that are
     * still assigned to a member or referenced by an invite are reported instead
     * of removed — dropping them would null out those role_id references.
     *
     * @return array<int, array{scope: string, key: string, status: string, changes: array<int, string>}>
     */
    private function pruneRemovedRoles(bool $dryRun): array
    {
        $results = [];
        $configured = [];

        foreach (config('authorization.roles', []) as $scope => $roles) {
            $configured[(string) $scope] = array_keys($roles);
        }

        $existing = Role::query()
            ->whereNull('team_id')
            ->where('is_system', true)
            ->get();

        foreach ($existing as $role) {
            $scope = $role->scope->value;

            if (in_array($role->key, $configured[$scope] ?? [], true)) {
                continue;
            }

            if ($this->isReferenced($role)) {
                $results[] = $this->result($scope, $role->key, self::IN_USE, []);

                continue;
            }

            if (! $dryRun) {
                $role->delete();
            }

            $results[] = $this->result($scope, $role->key, self::PRUNED, []);
        }

        return $results;
    }

    private function isReferenced(Role $role): bool
    {
        return DB::table('team_user')->where('role_id', $role->id)->exists()
            || DB::table('space_user')->where('role_id', $role->id)->exists()
            || DB::table('invites')->where('role_id', $role->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    private function diff(Role $role, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $attribute => $value) {
            // Ability order carries no meaning; comparing the sets keeps rows
            // seeded before abilities were sorted from reporting as drift.
            $current = $attribute === 'abilities'
                ? $this->normalizeAbilities($role->abilities ?? [])
                : $role->{$attribute};

            if ($current !== $value) {
                $changes[] = $attribute;
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    private function normalizeAbilities(array $abilities): array
    {
        $normalized = array_values(array_unique($abilities));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<int, string>  $changes
     * @return array{scope: string, key: string, status: string, changes: array<int, string>}
     */
    private function result(string $scope, string $key, string $status, array $changes): array
    {
        return compact('scope', 'key', 'status', 'changes');
    }
}
