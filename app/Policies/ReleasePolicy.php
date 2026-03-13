<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Release;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReleasePolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.view');
    }

    public function view(User $user, Release $release, Space $space): bool
    {
        return $this->viewAny($user, $space);
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.manage');
    }

    public function update(User $user, Release $release, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.manage');
    }

    public function delete(User $user, Release $release, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.manage');
    }

    public function publish(User $user, Release $release, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.publish');
    }

    public function commit(User $user, Release $release, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.manage');
    }

    public function cancel(User $user, Release $release, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.manage');
    }

    public function assignVersions(User $user, Release $release, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.manage');
    }

    public function removeVersions(User $user, Release $release, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'releases.manage');
    }
}
