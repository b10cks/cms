<?php

namespace App\Policies;

use App\Models\Management\Automation;
use App\Models\Management\Space;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

class AutomationPolicy
{
    use AuthorizesWithAbilities;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'automations.view');
    }

    public function view(User $user, Automation $automation, Space $space): bool
    {
        return $automation->space_id === $space->id
            && $this->canInSpace($user, $space, 'automations.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'automations.manage');
    }

    public function update(User $user, Automation $automation, Space $space): bool
    {
        return $automation->space_id === $space->id
            && $this->canInSpace($user, $space, 'automations.manage');
    }

    public function delete(User $user, Automation $automation, Space $space): bool
    {
        return $automation->space_id === $space->id
            && $this->canInSpace($user, $space, 'automations.manage');
    }
}
