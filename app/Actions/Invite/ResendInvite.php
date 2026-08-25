<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Management\InviteToTeamNotification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Notification;

class ResendInvite
{
    public const MAX_RESENDS_PER_HOUR = 5;

    public function execute(Invite $invite): Invite
    {
        if ($invite->isAccepted()) {
            throw ValidationException::withMessages([
                'invite' => ['Cannot resend an accepted invite.'],
            ]);
        }

        if ($invite->isDeclined()) {
            throw ValidationException::withMessages([
                'invite' => ['This invitation was declined. Create a new invite instead.'],
            ]);
        }

        // Resends go to an arbitrary address the recipient never confirmed —
        // without a cap this is a mail-bombing primitive.
        $allowed = RateLimiter::attempt(
            'invite-resend:'.$invite->id,
            self::MAX_RESENDS_PER_HOUR,
            static fn () => true,
            3600,
        );

        if (! $allowed) {
            throw ValidationException::withMessages([
                'invite' => ['This invitation was resent too often. Please try again later.'],
            ]);
        }

        $invite->forceFill([
            // Expired links have leaked out of a valid window; rotate them.
            ...($invite->isExpired() ? ['token' => Str::random(32)] : []),
            // Always give the recipient a fresh window to act.
            'expires_at' => now()->addDays(7)->max($invite->expires_at),
        ])->save();

        $this->sendNotification($invite);

        return $invite->refresh();
    }

    private function sendNotification(Invite $invite): void
    {
        if ($invite->space_id) {
            $notification = new InviteToSpaceNotification($invite, $invite->space, $invite->inviter);
        } elseif ($invite->team_id) {
            $notification = new InviteToTeamNotification($invite, $invite->team, $invite->inviter);
        } else {
            return;
        }

        Notification::route('mail', $invite->email)
            ->notify($notification->locale($invite->notificationLocale()));
    }
}
