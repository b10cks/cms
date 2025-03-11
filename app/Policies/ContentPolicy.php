<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ContentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $user->spaces()->where('spaces.id', $space->id)->exists() || $user->is_root;
    }

    public function view(User $user, Content $content, Space $space): bool
    {
        return $user->spaces()->where('spaces.id', $space->id)->exists() || $user->is_root;
    }

    public function create(User $user, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function update(User $user, Content $content, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function delete(User $user, Content $content, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function restore(User $user, Content $content, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function forceDelete(User $user, Content $content, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function publish(User $user, Content $content, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }

    public function viewHistory(User $user, Content $content, Space $space): bool
    {
        return $user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root;
    }
}
