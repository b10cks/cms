<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Auth\MembershipService;
use Illuminate\Database\Eloquent\Collection;

class TeamService
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function createTeam(array $data, User $creator): Team
    {
        $team = Team::create($data);

        // The creator owns the team they create so it is never orphaned and is
        // immediately manageable. Done before invalidation so the recomputed
        // graph includes the membership.
        if (! $creator->is_root) {
            $this->membershipService->assignTeamRole($team, $creator, 'owner');
        }

        $this->authorizationService->invalidateTeamReparent($team, null, $team->parent_id);

        return $team;
    }

    public function updateTeam(Team $team, array $data): Team
    {
        $originalParentId = $team->parent_id;
        $team->update($data);

        if ($originalParentId !== $team->parent_id) {
            $this->authorizationService->invalidateTeamReparent($team, $originalParentId, $team->parent_id);
        }

        return $team->fresh();
    }

    public function deleteTeam(Team $team): bool
    {
        // Child teams and owned spaces are the real blockers — they would be
        // orphaned by deletion.
        if ($team->children()->exists()) {
            throw new \Exception('Cannot delete team with child teams. Please reassign or delete child teams first.');
        }

        if ($team->spaces()->exists()) {
            throw new \Exception('Cannot delete team with associated spaces. Please reassign spaces first.');
        }

        // Direct memberships are detached as part of deletion (so an owner can
        // delete a team they just created). Capture them first to refresh their
        // authorization graphs afterwards.
        $memberIds = $team->users()->pluck('users.id');
        $parentId = $team->parent_id;
        $team->users()->detach();

        $deleted = $team->delete();

        $this->authorizationService->invalidateTeamReparent($team, $parentId, null);
        $this->authorizationService->invalidateUsers($memberIds);

        return $deleted;
    }

    /**
     * Build the team hierarchy rooted at $parentId.
     *
     * When $accessibleIds is null (root) the full tree is returned. Otherwise
     * the result is scoped to the user's accessible teams: we fetch the
     * accessible set flat and assemble the tree in PHP, treating any accessible
     * team whose parent is not itself accessible as a root node. This both
     * scopes every level and avoids hiding an accessible team that sits under an
     * inaccessible ancestor.
     *
     * @param  array<int, string>|null  $accessibleIds
     */
    public function getTeamHierarchy(?string $parentId = null, ?array $accessibleIds = null): Collection
    {
        if ($accessibleIds === []) {
            return new Collection();
        }

        $teams = Team::query()
            ->withCount(['users', 'spaces', 'children'])
            ->when($accessibleIds !== null, fn ($query) => $query->whereIn('id', $accessibleIds))
            ->orderBy('name')
            ->get();

        $accessibleSet = $accessibleIds === null ? null : array_flip($accessibleIds);
        $childrenByParent = $teams->groupBy('parent_id');

        $attachChildren = function (Team $team) use (&$attachChildren, $childrenByParent): Team {
            $children = $childrenByParent->get($team->id, new Collection())
                ->map(fn (Team $child) => $attachChildren($child))
                ->values();
            $team->setRelation('children', $children);

            return $team;
        };

        return $teams
            ->filter(function (Team $team) use ($parentId, $accessibleSet) {
                if ($parentId !== null) {
                    return $team->parent_id === $parentId;
                }

                // Full-tree mode: a team roots a visible subtree when it has no
                // parent, or its parent is outside the accessible set.
                return $team->parent_id === null
                    || ($accessibleSet !== null && ! isset($accessibleSet[$team->parent_id]));
            })
            ->map(fn (Team $team) => $attachChildren($team))
            ->values();
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
