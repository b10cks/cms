<?php

namespace App\Notifications\User;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class PasswordChangedNotification extends Notification
{
    public function __construct(public array $data)
    {
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.passwordChanged.subject'))
            ->line(new HtmlString(__('notifications.passwordChanged.intro', $this->data)))
            ->line(new HtmlString(__('notifications.passwordChanged.note', ['resetUrl' => route('password.request')])));
    }

    public function via(): array
    {
        return ['mail'];
    }
}
