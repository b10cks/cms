<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Management\SpaceMigration;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

class SpaceMigrationPolicy
{
    use AuthorizesWithAbilities;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'migrations.view');
    }

    public function view(User $user, SpaceMigration $migration): bool
    {
        return $this->canInSpace($user, $migration->sourceSpace, 'migrations.view')
            || $this->canInSpace($user, $migration->targetSpace, 'migrations.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'migrations.manage');
    }

    public function delete(User $user, SpaceMigration $migration): bool
    {
        return $this->canInSpace($user, $migration->sourceSpace, 'migrations.manage')
            || $this->canInSpace($user, $migration->targetSpace, 'migrations.manage');
    }
}
