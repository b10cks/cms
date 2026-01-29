<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SpacePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Space $space): bool
    {
        return
            $user->spaces()
                ->where('spaces.id', $space->id)->exists()
            || $user->teams()
                ->where('teams.id', $space->team_id)
                ->wherePivotIn('role', ['member', 'admin', 'owner'])
                ->exists()
            || $user->is_root;
    }

    public function create(User $user): bool
    {
        return $user->teams()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists();
    }

    public function update(User $user, Space $space): bool
    {
        return
            $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists()
            || $user->teams()
                ->where('teams.id', $space->team_id)
                ->wherePivotIn('role', ['owner'])
                ->exists()
            || $user->is_root;
    }

    public function delete(User $user, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivot('role', 'owner')
            ->exists()
            || $user->teams()
                ->where('teams.id', $space->team_id)
                ->wherePivotIn('role', ['owner'])
                ->exists()
            || $user->is_root;
    }

    public function archive(User $user, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->wherePivot('role', 'owner')
            ->exists()
            || $user->teams()
                ->where('teams.id', $space->team_id)
                ->wherePivotIn('role', ['owner'])
                ->exists()
            || $user->is_root;
    }
}
