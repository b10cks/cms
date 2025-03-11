<?php

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class OneTimeTokenNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public string $code)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.oneTimeToken.subject', ['code' => $this->code]))
            ->greeting(__('notifications.oneTimeToken.greeting'))
            ->line(__('notifications.oneTimeToken.intro'))
            ->line(new HtmlString('<h1>' . $this->code . '</h1>'))
            ->line(__('notifications.oneTimeToken.outro'));
    }
}
