<?php

namespace App\Notifications\Management;

use App\Models\Management\Invite;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class InviteToTeamNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invite $invite,
        public Team $team,
        public User $inviter
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = config('app.frontend_url') . '/invites/accept/' . $this->invite->token;

        return (new MailMessage())
            ->subject(__('notifications.inviteTeam.subject', ['team' => $this->team->name]))
            ->greeting(' ')
            ->line(new HtmlString(__('notifications.inviteTeam.intro', [
                'inviter' => $this->inviter->display_name,
                'team' => $this->team->name
            ])))
            ->when($this->invite->message, fn($mail) =>
                $mail->line(new HtmlString('<blockquote style="border-left: 4px solid #ccc; padding-left: 16px; margin-left: 0;">' . e($this->invite->message) . '</blockquote>'))
            )
            ->line(new HtmlString(__('notifications.inviteTeam.start')))
            ->action(__('notifications.inviteTeam.action'), $acceptUrl)
            ->salutation(' ')
            ->line(new HtmlString(__('notifications.inviteTeam.outro', [
                'expires' => $this->invite->expires_at->diffForHumans()
            ])));
    }
}
