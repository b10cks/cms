<?php

namespace App\Policies;

use App\Models\Management\AutomationAction;
use App\Models\Management\Space;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

class AutomationActionPolicy
{
    use AuthorizesWithAbilities;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'automation_actions.view');
    }

    public function view(User $user, AutomationAction $automationAction, Space $space): bool
    {
        return $automationAction->space_id === $space->id
            && $this->canInSpace($user, $space, 'automation_actions.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'automation_actions.manage');
    }

    public function update(User $user, AutomationAction $automationAction, Space $space): bool
    {
        return $automationAction->space_id === $space->id
            && $this->canInSpace($user, $space, 'automation_actions.manage');
    }

    public function delete(User $user, AutomationAction $automationAction, Space $space): bool
    {
        return $automationAction->space_id === $space->id
            && $this->canInSpace($user, $space, 'automation_actions.manage');
    }
}
