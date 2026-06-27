<?php

namespace App\Notifications\Management;

use App\Enums\NotificationType;
use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\User;
use App\Notifications\Concerns\DeliversInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class InviteToSpaceNotification extends Notification implements ShouldQueue
{
    use DeliversInApp;
    use Queueable;

    public function __construct(
        public Invite $invite,
        public Space $space,
        public User $inviter
    ) {}

    public function notificationType(): NotificationType
    {
        return NotificationType::InviteToSpace;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invite' => [
                'id' => $this->invite->id,
            ],
            'space' => [
                'id' => $this->space->id,
                'name' => $this->space->name,
            ],
            'team' => $this->space->team ? [
                'id' => $this->space->team->id,
                'name' => $this->space->team->name,
            ] : null,
            'inviter' => [
                'id' => $this->inviter->id,
                'display_name' => $this->inviter->display_name,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = sprintf(
            '%s/invites/%s?%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $this->invite->id,
            http_build_query(['invite_token' => $this->invite->token])
        );

        return (new MailMessage)
            ->subject(__('notifications.inviteSpace.subject', ['space' => $this->space->name, 'team' => $this->space->team?->name ?? __('notifications.teamFallback')]))
            ->greeting(__('notifications.greeting'))
            ->line(new HtmlString(__('notifications.inviteSpace.intro', [
                'inviter' => $this->inviter?->display_name ?? __('notifications.inviterFallback'),
                'space' => $this->space->name,
                'team' => $this->space->team?->name ?? __('notifications.teamFallback'),
                'role' => $this->invite->role,
            ])))
            ->when(
                $this->invite->message,
                fn ($mail) => $mail->line(new HtmlString('<blockquote style="border-left: 4px solid #ccc; padding-left: 16px; margin-left: 0;">'.e($this->invite->message).'</blockquote>'))
            )
            ->line(new HtmlString(__('notifications.inviteSpace.start')))
            ->action(__('notifications.inviteSpace.action'), $acceptUrl)
            ->salutation(' ')
            ->line(new HtmlString(__('notifications.inviteSpace.outro', [
                'expires' => $this->invite->expires_at->diffForHumans(),
            ])));
    }
}
