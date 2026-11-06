<?php

namespace App\Notifications\User;

use App\Notifications\Concerns\EscapesMailPlaceholders;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class PasswordChangedNotification extends Notification
{
    use EscapesMailPlaceholders;

    public function __construct(public array $data)
    {
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.passwordChanged.subject'))
            ->line(new HtmlString(__('notifications.passwordChanged.intro', $this->escapeForMail($this->data))))
            ->line(new HtmlString(__('notifications.passwordChanged.note', $this->escapeForMail(['resetUrl' => url('/login/password')]))));
    }

    public function via(): array
    {
        return ['mail'];
    }
}
