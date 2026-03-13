<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class BlockPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'blocks.view');
    }

    public function view(User $user, Block $block, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'blocks.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'blocks.manage');
    }

    public function update(User $user, Block $block, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'blocks.manage');
    }

    public function delete(User $user, Block $block, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'blocks.manage');
    }
}
