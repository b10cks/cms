<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use App\Services\Auth\AuthorizationService;
use App\Services\Auth\MembershipService;
use Illuminate\Database\Eloquent\Collection;

class TeamService
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function createTeam(array $data): Team
    {
        $team = Team::create($data);
        $this->authorizationService->invalidateTeamTree($team);

        return $team;
    }

    public function updateTeam(Team $team, array $data): Team
    {
        $originalParentId = $team->parent_id;
        $team->update($data);

        if ($originalParentId !== $team->parent_id) {
            $this->authorizationService->invalidateTeamTree($team);
        }

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
        $this->membershipService->assignTeamRole($team, $userId, $role ?? 'member');
    }

    public function detachUser(Team $team, string $userId): void
    {
        $this->membershipService->removeTeamMembership($team, $userId);
    }

    public function updateUserRole(Team $team, string $userId, ?string $role): void
    {
        if (! $role) {
            $this->membershipService->removeTeamMembership($team, $userId);

            return;
        }

        $this->membershipService->assignTeamRole($team, $userId, $role);
    }

    public function getTeamPath(Team $team): array
    {
        $path = [];
        $current = $team;

        while ($current) {
            array_unshift($path, [
                'id' => $current->id,
                'name' => $current->name,
            ]);
            $current = $current->parent;
        }

        return $path;
    }
}
