<?php

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.twoFactorDisabled.subject'))
            ->greeting(__('notifications.twoFactorDisabled.greeting', ['name' => $notifiable->firstname]))
            ->line(__('notifications.twoFactorDisabled.intro'))
            ->line(__('notifications.twoFactorDisabled.outro'));
    }
}
