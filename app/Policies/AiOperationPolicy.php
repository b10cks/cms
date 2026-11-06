<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

/**
 * Operations are private to their actor, like runs. One a schedule started
 * belongs to everyone who manages the space's AI, like the schedule's thread.
 * Steering one (approve, pause, budget) also needs the right to start runs on
 * its surface. Approving content work writes drafts, so it also needs the right to
 * edit content: an operation never does what its approver could not do by hand.
 */
class AiOperationPolicy
{
    use AuthorizesWithAbilities;

    public function view(User $user, AiOperation $operation, Space $space): bool
    {
        if ($operation->space_id !== $space->id || ! $this->canInSpace($user, $space, 'space.view')) {
            return false;
        }

        return $operation->actor_id === $user->id
            || ($operation->schedule_id !== null && $this->canInSpace($user, $space, 'ai.manage'));
    }

    public function update(User $user, AiOperation $operation, Space $space): bool
    {
        return $this->view($user, $operation, $space) && app(AiRunPolicy::class)->create($user, $space, $operation->surface);
    }

    public function approve(User $user, AiOperation $operation, Space $space): bool
    {
        return $this->update($user, $operation, $space)
            && (($operation->scope['type'] ?? null) !== AiChangesetItem::TARGET_CONTENT || $this->canInSpace($user, $space, 'content.manage'));
    }
}
