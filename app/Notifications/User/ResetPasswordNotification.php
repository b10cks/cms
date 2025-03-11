<?php

namespace App\Notifications\User;

use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends \Illuminate\Auth\Notifications\ResetPassword
{
    protected function buildMailMessage($url)
    {
        return (new MailMessage)
            ->subject(__('notifications.passwordReset.subject'))
            ->line(__('notifications.passwordReset.intro'))
            ->action(__('notifications.passwordReset.action'), $url)
            ->line(
                __(
                    'notifications.passwordReset.expireNote',
                    ['count' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire')]
                )
            )
            ->line(__('notifications.passwordReset.note'));
    }
}
