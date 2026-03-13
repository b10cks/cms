<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\BlockTemplate;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class BlockTemplatePolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_templates.view');
    }

    public function view(User $user, BlockTemplate $template, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_templates.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_templates.manage');
    }

    public function update(User $user, BlockTemplate $template, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_templates.manage');
    }

    public function delete(User $user, BlockTemplate $template, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'block_templates.manage');
    }
}
