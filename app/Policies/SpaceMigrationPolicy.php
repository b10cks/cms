<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Management\SpaceMigration;
use App\Models\User;

class SpaceMigrationPolicy
{
    public function viewAny(User $user, Space $space): bool
    {
        return $this->userHasAccessToSpace($user, $space);
    }

    public function view(User $user, SpaceMigration $migration): bool
    {
        return $this->userHasAccessToSpace($user, $migration->sourceSpace)
            || $this->userHasAccessToSpace($user, $migration->targetSpace);
    }

    public function create(User $user, Space $space): bool
    {
        return $this->userHasAccessToSpace($user, $space);
    }

    public function delete(User $user, SpaceMigration $migration): bool
    {
        return $this->userHasAccessToSpace($user, $migration->sourceSpace)
            || $this->userHasAccessToSpace($user, $migration->targetSpace);
    }

    private function userHasAccessToSpace(User $user, Space $space): bool
    {
        return $user->spaces()->where('spaces.id', $space->id)->exists()
            || $user->teams()->where('teams.id', $space->team_id)->exists()
            || $user->is_root;
    }
}
