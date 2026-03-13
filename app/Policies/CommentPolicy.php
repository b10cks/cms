<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Comment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class CommentPolicy
{
    use AuthorizesWithAbilities;
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'comments.view');
    }

    public function view(User $user, Comment $comment, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'comments.view');
    }

    public function create(User $user, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'comments.create');
    }

    public function update(User $user, Comment $comment, Space $space): bool
    {
        return $user->is_root
            || ($comment->author_id === $user->id && $this->canInSpace($user, $space, 'comments.update_own'));
    }

    public function delete(User $user, Comment $comment, Space $space): bool
    {
        return $user->is_root
            || ($comment->author_id === $user->id && $this->canInSpace($user, $space, 'comments.delete_own'));
    }

    public function resolve(User $user, Comment $comment, Space $space): bool
    {
        return $user->is_root
            || ($comment->author_id === $user->id && $this->canInSpace($user, $space, 'comments.resolve_own'));
    }

    public function unresolve(User $user, Comment $comment, Space $space): bool
    {
        return $this->resolve($user, $comment, $space);
    }

    public function react(User $user, Comment $comment, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'comments.react');
    }

    public function unreact(User $user, Comment $comment, Space $space): bool
    {
        return $this->canInSpace($user, $space, 'comments.react');
    }
}
