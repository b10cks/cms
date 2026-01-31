<?php

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
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
            ->greeting(__('notifications.verifyEmail.greeting', ['name' => $notifiable->firstname]))
            ->line(__('notifications.verifyEmail.intro'))
            ->action(__('notifications.verifyEmail.action'), $this->verificationUrl)
            ->line(__('notifications.verifyEmail.outro'));
    }
}
