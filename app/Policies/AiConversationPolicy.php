<?php

namespace App\Policies;

use App\Enums\AiThreadKind;
use App\Models\Management\Space;
use App\Models\Space\AiConversation;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

/**
 * Chat threads are private to their owner until shared with the space.
 * Schedule and suggestion threads belong to those who manage the space's AI.
 * Matches {@see AiConversation::scopeVisibleTo()}.
 */
class AiConversationPolicy
{
    use AuthorizesWithAbilities;

    public function view(User $user, AiConversation $thread, Space $space): bool
    {
        if ($thread->space_id !== $space->id || ! $this->canInSpace($user, $space, 'space.view')) {
            return false;
        }

        return $thread->kind === AiThreadKind::Chat
            ? $thread->user_id === $user->id || $thread->shared
            : $this->canInSpace($user, $space, 'ai.manage');
    }

    /** Renaming and sharing are the owner's. */
    public function update(User $user, AiConversation $thread, Space $space): bool
    {
        return $thread->space_id === $space->id
            && $thread->user_id !== null
            && $thread->user_id === $user->id
            && $this->canInSpace($user, $space, 'space.view');
    }

    /** The owner archives a chat thread; `ai.manage` the standing threads. */
    public function archive(User $user, AiConversation $thread, Space $space): bool
    {
        return $thread->kind === AiThreadKind::Chat
            ? $this->update($user, $thread, $space)
            : $thread->space_id === $space->id && $this->canInSpace($user, $space, 'ai.manage');
    }
}
