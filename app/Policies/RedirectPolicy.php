<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class RedirectPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'redirects.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Redirect $redirect, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'redirects.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'redirects.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Redirect $redirect, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'redirects.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Redirect $redirect, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'redirects.manage');
    }
}
