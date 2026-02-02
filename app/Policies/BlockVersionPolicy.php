<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockVersion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BlockVersionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space, Block $block): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->exists() || $user->is_root;
    }

    public function view(User $user, BlockVersion $version, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->exists() || $user->is_root;
    }

    public function delete(User $user, BlockVersion $version, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists() || $user->is_root;
    }

    public function updateCommit(User $user, BlockVersion $version, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists() || $user->is_root;
    }

    public function restore(User $user, BlockVersion $version, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists() || $user->is_root;
    }
}
