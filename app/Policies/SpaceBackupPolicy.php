<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

class SpaceBackupPolicy
{
    use AuthorizesWithAbilities;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'backups.view');
    }

    public function view(User $user, SpaceBackup $backup): bool
    {
        return $this->canInSpace($user, $backup->space, 'backups.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'backups.manage');
    }

    public function update(User $user, SpaceBackup $backup): bool
    {
        return $this->canInSpace($user, $backup->space, 'backups.manage');
    }

    public function delete(User $user, SpaceBackup $backup): bool
    {
        return $this->canInSpace($user, $backup->space, 'backups.manage');
    }
}
