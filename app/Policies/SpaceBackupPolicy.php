<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Models\User;

class SpaceBackupPolicy
{
    public function viewAny(User $user, Space $space): bool
    {
        return $user->spaces()->where('spaces.id', $space->id)->exists()
            || $user->teams()->where('teams.id', $space->team_id)->exists()
            || $user->is_root;
    }

    public function view(User $user, SpaceBackup $backup): bool
    {
        return $this->userHasAccessToSpace($user, $backup->space);
    }

    public function create(User $user, Space $space): bool
    {
        return $this->userHasAccessToSpace($user, $space);
    }

    public function update(User $user, SpaceBackup $backup): bool
    {
        return $this->userHasAccessToSpace($user, $backup->space);
    }

    public function delete(User $user, SpaceBackup $backup): bool
    {
        return $this->userHasAccessToSpace($user, $backup->space);
    }

    private function userHasAccessToSpace(User $user, Space $space): bool
    {
        return $user->spaces()->where('spaces.id', $space->id)->exists()
            || $user->teams()->where('teams.id', $space->team_id)->exists()
            || $user->is_root;
    }
}
