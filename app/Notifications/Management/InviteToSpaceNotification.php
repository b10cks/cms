<?php

namespace App\Notifications\Management;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class InviteToSpaceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invite $invite,
        public Space $space,
        public User $inviter
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = ($this->invite->invitee_id)
            ? config('app.frontend_url') . '/invites/accept/' . $this->invite->id . '?invite_token=' . $this->invite->token
            : config('app.frontend_url') . '/login/signup?invite_id=' . $this->invite->id . '&invite_token=' . $this->invite->token;

        return (new MailMessage())
            ->subject(__('notifications.inviteSpace.subject', ['space' => $this->space->name, 'team' => $this->space->team->name]))
            ->greeting(' ')
            ->line(new HtmlString(__('notifications.inviteSpace.intro', [
                'inviter' => $this->inviter->display_name,
                'space' => $this->space->name,
                'team' => $this->space->team->name,
                'role' => $this->invite->role
            ])))
            ->when(
                $this->invite->message,
                fn($mail) =>
                $mail->line(new HtmlString('<blockquote style="border-left: 4px solid #ccc; padding-left: 16px; margin-left: 0;">' . e($this->invite->message) . '</blockquote>'))
            )
            ->line(new HtmlString(__('notifications.inviteSpace.start')))
            ->action(__('notifications.inviteSpace.action'), $acceptUrl)
            ->salutation(' ')
            ->line(new HtmlString(__('notifications.inviteSpace.outro', [
                'expires' => $this->invite->expires_at->diffForHumans()
            ])));
    }
}
