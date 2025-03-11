<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RedirectPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Space $space): bool
    {
        // Anyone with access to the space can view redirects
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Redirect $redirect, Space $space): bool
    {
        // Anyone with access to the space can view a specific redirect
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Space $space): bool
    {
        // Only owners, admins, and editors can create redirects
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin', 'editor'])
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Redirect $redirect, Space $space): bool
    {
        // Only owners, admins, and editors can update redirects
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin', 'editor'])
                ->exists() || $user->is_root;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Redirect $redirect, Space $space): bool
    {
        // Only owners, admins, and editors can delete redirects
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin', 'editor'])
                ->exists() || $user->is_root;
    }
}
