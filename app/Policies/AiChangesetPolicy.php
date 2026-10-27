<?php

namespace App\Policies;

use App\Models\Management\AiChangeset;
use App\Models\Management\Space;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

/**
 * Background changesets are the space's inbox; inline ones belong to the
 * editor who ran them. Deciding on items is checked per target.
 */
class AiChangesetPolicy
{
    use AuthorizesWithAbilities;

    public function view(User $user, AiChangeset $changeset, Space $space): bool
    {
        return $changeset->space_id === $space->id
            && ($changeset->actor_id === $user->id || ! $changeset->inline)
            && $this->canInSpace($user, $space, 'space.view');
    }
}
