<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class ContentPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.view');
    }

    public function view(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.manage');
    }

    public function update(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.manage');
    }

    public function delete(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.manage');
    }

    public function restore(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.manage');
    }

    public function forceDelete(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.manage');
    }

    public function publish(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.publish');
    }

    public function schedule(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.publish');
    }

    public function viewHistory(User $user, Content $content, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'content.history.view');
    }
}
