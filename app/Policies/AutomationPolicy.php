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
        // Triggering requires discovering what is triggerable, so the trigger
        // ability implies listing/reading automations (never their secrets).
        return $this->canInSpace($user, $space, 'automations.view')
            || $this->canInSpace($user, $space, 'automations.trigger');
    }

    public function view(User $user, Automation $automation, Space $space): bool
    {
        return $automation->space_id === $space->id
            && ($this->canInSpace($user, $space, 'automations.view')
                || $this->canInSpace($user, $space, 'automations.trigger'));
    }

    public function trigger(User $user, Automation $automation, Space $space): bool
    {
        return $automation->space_id === $space->id
            && $this->canInSpace($user, $space, 'automations.trigger');
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
