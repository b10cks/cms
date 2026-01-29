<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Management\InviteToTeamNotification;
use Illuminate\Support\Str;
use Notification;

class ResendInvite
{
    public function execute(Invite $invite): Invite
    {
        if ($invite->isAccepted()) {
            throw new \Exception('Cannot resend an accepted invite.');
        }

        if ($invite->isExpired()) {
            $invite->update([
                'token' => Str::random(32),
                'expires_at' => now()->addDays(7),
            ]);
        }

        $this->sendNotification($invite);

        return $invite->refresh();
    }

    private function sendNotification(Invite $invite): void
    {
        if ($invite->space_id) {
            $space = $invite->space;
            Notification::route('mail', $invite->email)
                ->notify(new InviteToSpaceNotification($invite, $space, $invite->inviter));
        } elseif ($invite->team_id) {
            $team = $invite->team;
            Notification::route('mail', $invite->email)
                ->notify(new InviteToTeamNotification($invite, $team, $invite->inviter));
        }
    }
}
