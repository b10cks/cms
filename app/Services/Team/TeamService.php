<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TeamService
{
    public function createTeam(array $data): Team
    {
        return Team::create($data);
    }

    public function updateTeam(Team $team, array $data): Team
    {
        $team->update($data);
        return $team->fresh();
    }

    public function deleteTeam(Team $team): bool
    {
        // Check if team has children
        if ($team->children()->exists()) {
            throw new \Exception('Cannot delete team with child teams. Please reassign or delete child teams first.');
        }

        // Check if team has users
        if ($team->users()->exists()) {
            throw new \Exception('Cannot delete team with assigned users. Please reassign users first.');
        }

        // Check if team has spaces
        if ($team->spaces()->exists()) {
            throw new \Exception('Cannot delete team with associated spaces. Please reassign spaces first.');
        }

        return $team->delete();
    }

    public function getTeamHierarchy(?string $parentId = null): Collection
    {
        return Team::with(['children', 'users', 'spaces'])
            ->where('parent_id', $parentId)
            ->withCount(['users', 'spaces', 'children'])
            ->orderBy('name')
            ->get();
    }

    public function attachUser(Team $team, string $userId, ?string $role = null): void
    {
        $team->users()->syncWithoutDetaching([
            $userId => ['role' => $role, 'created_at' => now(), 'updated_at' => now()]
        ]);
    }

    public function detachUser(Team $team, string $userId): void
    {
        $team->users()->detach($userId);
    }

    public function updateUserRole(Team $team, string $userId, ?string $role): void
    {
        $team->users()->updateExistingPivot($userId, [
            'role' => $role,
            'updated_at' => now()
        ]);
    }

    public function getTeamPath(Team $team): array
    {
        $path = [];
        $current = $team;

        while ($current) {
            array_unshift($path, [
                'id' => $current->id,
                'name' => $current->name
            ]);
            $current = $current->parent;
        }

        return $path;
    }
}
