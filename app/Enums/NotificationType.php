<?php

namespace App\Enums;

/**
 * Stable, frontend-facing notification type identifiers.
 *
 * The value is persisted in the `type` column of the notifications table and
 * sent over the broadcast channel, so the frontend can render type-specific
 * copy without depending on PHP class names.
 */
enum NotificationType: string
{
    case CommentMention = 'comment.mention';
    case CommentReply = 'comment.reply';
    case InviteToSpace = 'invite.space';
    case InviteToTeam = 'invite.team';
}
