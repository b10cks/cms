<?php

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorBackupCodesRegeneratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.twoFactorBackupCodesRegenerated.subject'))
            ->greeting(__('notifications.twoFactorBackupCodesRegenerated.greeting', ['name' => $notifiable->firstname]))
            ->line(__('notifications.twoFactorBackupCodesRegenerated.intro'))
            ->line(__('notifications.twoFactorBackupCodesRegenerated.outro'));
    }
}