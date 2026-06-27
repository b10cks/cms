<?php

namespace App\Observers\Space;

use App\Models\Management\Space;
use App\Models\Space\Comment;
use App\Models\Space\Content;
use App\Models\User;
use App\Notifications\Space\CommentReplyNotification;
use App\Notifications\Space\MentionNotification;
use Illuminate\Support\Str;

/**
 * Turns comment activity into user notifications.
 *
 * Comments live in the per-space database while users and notifications live in
 * the management database, so the current space is resolved from the container
 * (the same pattern used by {@see Content}) and only plain
 * scalar data is handed to the queued notifications.
 */
class CommentObserver
{
    public function created(Comment $comment): void
    {
        $space = app()->bound('currentSpace') ? app('currentSpace') : null;

        if (! $space instanceof Space) {
            return;
        }

        $content = $comment->content;

        if (! $content) {
            return;
        }

        $spaceData = ['id' => $space->id, 'name' => $space->name];
        $contentData = ['id' => $content->id, 'name' => $content->name ?? null];
        $author = $comment->author;
        $authorData = [
            'id' => $comment->author_id,
            'display_name' => $author?->display_name ?? '',
        ];
        $excerpt = Str::limit(trim(strip_tags((string) $comment->body)), 140);

        $notifiedUserIds = [$comment->author_id];

        // Reply: notify the parent comment's author.
        if ($comment->parent_id && ($parent = $comment->parent)) {
            if ($parent->author_id && ! in_array($parent->author_id, $notifiedUserIds, true)) {
                $this->notify(
                    $parent->author_id,
                    new CommentReplyNotification($spaceData, $contentData, $authorData, $comment->item_id, $comment->field, $excerpt)
                );
                $notifiedUserIds[] = $parent->author_id;
            }
        }

        // Mentions: notify every @-mentioned user not already notified.
        foreach (array_unique($comment->mentions_ids ?? []) as $userId) {
            if (in_array($userId, $notifiedUserIds, true)) {
                continue;
            }

            $this->notify(
                $userId,
                new MentionNotification($spaceData, $contentData, $authorData, $comment->item_id, $comment->field, $excerpt)
            );
            $notifiedUserIds[] = $userId;
        }
    }

    private function notify(string $userId, object $notification): void
    {
        User::find($userId)?->notify($notification);
    }
}
