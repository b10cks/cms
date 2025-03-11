<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BlockPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        // Allow if user is member of the space (any role) or is root
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function view(User $user, Block $block, Space $space): bool
    {
        // Allow if user is member of the space (any role) or is root
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function create(User $user, Space $space): bool
    {
        // Allow if user has admin or owner role in the specific space
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists() || $user->is_root;
    }

    public function update(User $user, Block $block, Space $space): bool
    {
        // Allow if user has admin or owner role in the specific space
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists() || $user->is_root;
    }

    public function delete(User $user, Block $block, Space $space): bool
    {
        // Allow if user has admin or owner role in the specific space
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists() || $user->is_root;
    }
}
