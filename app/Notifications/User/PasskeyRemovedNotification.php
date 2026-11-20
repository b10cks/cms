<?php

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasskeyRemovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $passkeyName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.passkeyRemoved.subject'))
            ->greeting(__('notifications.passkeyRemoved.greeting', ['name' => $notifiable->firstname]))
            ->line(__('notifications.passkeyRemoved.intro', ['passkey' => $this->passkeyName]))
            ->line(__('notifications.passkeyRemoved.outro'));
    }
}
