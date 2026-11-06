<?php

namespace App\Notifications\User;

use App\Notifications\Concerns\EscapesMailPlaceholders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use EscapesMailPlaceholders;
    use Queueable;

    public function __construct(public string $verificationUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.verifyEmail.subject'))
            // Anyone can register with someone else's address, so the name is
            // attacker-chosen in mail that lands in a stranger's inbox.
            ->greeting(__('notifications.verifyEmail.greeting', ['name' => $this->escapeMarkdown((string) $notifiable->firstname)]))
            ->line(__('notifications.verifyEmail.intro'))
            ->action(__('notifications.verifyEmail.action'), $this->verificationUrl)
            ->line(__('notifications.verifyEmail.outro'));
    }
}
