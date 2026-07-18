<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\FieldPlugin;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class FieldPluginPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'field_plugins.view');
    }

    public function view(User $user, FieldPlugin $fieldPlugin, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'field_plugins.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'field_plugins.manage');
    }

    public function update(User $user, FieldPlugin $fieldPlugin, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'field_plugins.manage');
    }

    public function delete(User $user, FieldPlugin $fieldPlugin, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'field_plugins.manage');
    }
}
