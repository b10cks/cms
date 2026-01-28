<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Release;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReleasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $user->spaces()->where('spaces.id', $space->id)->exists() || $user->is_root;
    }

    public function view(User $user, Release $release, Space $space): bool
    {
        return $this->viewAny($user, $space);
    }

    public function create(User $user, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin', 'editor'])
            ->exists()) || $user->is_root;
    }

    public function update(User $user, Release $release, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin', 'editor'])
            ->exists()) || $user->is_root;
    }

    public function delete(User $user, Release $release, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists()) || $user->is_root;
    }

    public function publish(User $user, Release $release, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists()) || $user->is_root;
    }

    public function commit(User $user, Release $release, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin', 'editor'])
            ->exists()) || $user->is_root;
    }

    public function cancel(User $user, Release $release, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin', 'editor'])
            ->exists()) || $user->is_root;
    }

    public function assignVersions(User $user, Release $release, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin', 'editor'])
            ->exists()) || $user->is_root;
    }

    public function removeVersions(User $user, Release $release, Space $space): bool
    {
        return ($user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivotIn('role', ['owner', 'admin', 'editor'])
            ->exists()) || $user->is_root;
    }
}
