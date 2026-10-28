<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

/**
 * Inline changesets belong to the editor who ran them. Others follow their
 * thread's visibility; changesets from before threads stay open to the space's
 * members. Deciding on items is checked per target.
 */
class AiChangesetPolicy
{
    use AuthorizesWithAbilities;

    public function view(User $user, AiChangeset $changeset, Space $space): bool
    {
        if ($changeset->space_id !== $space->id || ! $this->canInSpace($user, $space, 'space.view')) {
            return false;
        }

        if ($changeset->inline) {
            return $changeset->actor_id === $user->id;
        }

        $thread = $changeset->run?->conversation;

        return $thread === null || app(AiConversationPolicy::class)->view($user, $thread, $space);
    }
}
