<?php

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasskeyAddedNotification extends Notification implements ShouldQueue
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
            ->subject(__('notifications.passkeyAdded.subject'))
            ->greeting(__('notifications.passkeyAdded.greeting', ['name' => $notifiable->firstname]))
            ->line(__('notifications.passkeyAdded.intro', ['passkey' => $this->passkeyName]))
            ->line(__('notifications.passkeyAdded.outro'));
    }
}
