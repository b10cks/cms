<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\DataSource;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DataSourcePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }
    public function view(User $user, DataSource $dataSource, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }
    public function create(User $user, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists() || $user->is_root;
    }

    public function update(User $user, DataSource $dataSource, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists() || $user->is_root;
    }

    public function delete(User $user, DataSource $dataSource, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists() || $user->is_root;
    }
}
