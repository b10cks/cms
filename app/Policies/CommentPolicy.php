<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Space\Comment;
use App\Models\Space\CommentReaction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CommentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->exists() || $user->is_root;
    }

    public function view(User $user, Comment $comment, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->exists() || $user->is_root;
    }

    public function create(User $user, Space $space): bool
    {
        return $user->spaces()
            ->where('spaces.id', $space->id)
            ->exists() || $user->is_root;
    }

    public function update(User $user, Comment $comment, Space $space): bool
    {
        return ($comment->author_id === $user->id || $user->is_root);
    }

    public function delete(User $user, Comment $comment, Space $space): bool
    {
        return ($comment->author_id === $user->id || $user->is_root);
    }

    public function resolve(User $user, Comment $comment, Space $space): bool
    {
        return ($comment->author_id === $user->id || $user->is_root)
            && ($user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root);
    }

    public function unresolve(User $user, Comment $comment, Space $space): bool
    {
        return ($comment->author_id === $user->id || $user->is_root)
            && ($user->spaces()
                ->where('spaces.id', $space->id)
                ->exists() || $user->is_root);
    }

    public function react(User $user, Comment $comment, Space $space): bool
    {
        return $user->is_root || $user->spaces()
            ->where('spaces.id', $space->id)
            ->exists();
    }

    public function unreact(User $user, Comment $comment, Space $space): bool
    {
        return $user->is_root || $user->spaces()
            ->where('spaces.id', $space->id)
            ->exists();
    }
}
