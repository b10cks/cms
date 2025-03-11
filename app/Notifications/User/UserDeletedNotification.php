<?php

namespace App\Notifications\User;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserDeletedNotification extends Notification
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.userDeleted.subject'))
            ->line(__('notifications.userDeleted.intro'))
            ->line(__('notifications.userDeleted.note'));
    }

    public function via(): array
    {
        return ['mail'];
    }
}
