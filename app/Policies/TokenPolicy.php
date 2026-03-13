<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class TokenPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any tokens.
     */
    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.tokens.view');
    }

    /**
     * Determine whether the user can view the token.
     */
    public function view(User $user, Token $token, Space $space): bool
    {
        return $token->space_id === $space->id
            && $this->canInSpace($user, $space, 'space.tokens.view');
    }

    /**
     * Determine whether the user can create tokens.
     */
    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'space.tokens.manage');
    }

    /**
     * Determine whether the user can delete the token.
     */
    public function delete(User $user, Token $token, Space $space): bool
    {
        return $token->space_id === $space->id
            && $this->canInSpace($user, $space, 'space.tokens.manage');
    }
}
