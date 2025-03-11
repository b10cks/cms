<?php

namespace App\Policies;

use App\Models\Management\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_root || $user->teams()->exists();
    }

    public function view(User $user, Team $team): bool
    {
        if ($user->is_root) {
            return true;
        }

        return $user->teams()->whereIn('teams.id', $this->getAccessibleTeamIds($user, $team))->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Team $team): bool
    {
        if ($user->is_root) {
            return true;
        }

        return $user->teams()
            ->wherePivot('role', 'admin')
            ->where('teams.id', $team->id)
            ->exists();
    }

    public function delete(User $user, Team $team): bool
    {
        if ($user->is_root) {
            return true;
        }

        return $user->teams()
            ->wherePivot('role', 'admin')
            ->where('teams.id', $team->id)
            ->exists();
    }

    private function getAccessibleTeamIds(User $user, Team $team): array
    {
        $accessibleIds = [];

        if ($user->teams()->where('teams.id', $team->id)->exists()) {
            $accessibleIds[] = $team->id;
        }

        $this->addChildTeamIds($user, $team, $accessibleIds);

        return $accessibleIds;
    }

    private function addChildTeamIds(User $user, Team $team, array &$accessibleIds): void
    {
        $childTeams = $team->children;
        foreach ($childTeams as $child) {
            if ($user->teams()->where('teams.id', $child->id)->exists()) {
                $accessibleIds[] = $child->id;
                $this->addChildTeamIds($user, $child, $accessibleIds);
            }
        }
    }
}
