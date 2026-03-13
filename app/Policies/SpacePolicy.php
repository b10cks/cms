<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class SpacePolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.view');
    }

    public function create(User $user): bool
    {
        return $user->is_root || $this->authorization()->accessibleTeamIds($user) !== [];
    }

    public function update(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.update');
    }

    public function delete(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.delete');
    }

    public function archive(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.archive');
    }

    public function viewMembers(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.members.view');
    }

    public function manageMembers(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.members.manage');
    }

    public function viewInvites(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.invites.view');
    }

    public function manageInvites(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.invites.manage');
    }

    public function viewBilling(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.billing.view');
    }

    public function manageBilling(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.billing.manage');
    }
}
