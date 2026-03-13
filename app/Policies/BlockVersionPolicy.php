<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockVersion;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class BlockVersionPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space, Block $block): bool
    {
        return $this->canInSpace($user, $space, 'block_versions.view');
    }

    public function view(User $user, BlockVersion $version, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_versions.view');
    }

    public function delete(User $user, BlockVersion $version, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_versions.manage');
    }

    public function updateCommit(User $user, BlockVersion $version, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_versions.manage');
    }

    public function restore(User $user, BlockVersion $version, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_versions.manage');
    }
}
