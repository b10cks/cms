<?php

namespace App\Notifications\Management;

use App\Models\Management\Space;
use App\Models\Management\SpaceBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class BackupReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SpaceBackup $backup,
        public Space $space,
        public string $downloadUrl
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject(__('notifications.backupReady.subject', ['space' => $this->space->name]))
            ->greeting(__('notifications.greeting'))
            ->line(new HtmlString(__('notifications.backupReady.intro', [
                'space' => $this->space->name,
                'name' => $this->backup->name,
            ])));

        if ($this->backup->password) {
            $mail->line(new HtmlString(__('notifications.backupReady.passwordNotice')));
        }

        $mail->action(__('notifications.backupReady.action'), $this->downloadUrl)
            ->line(new HtmlString(__('notifications.backupReady.expires', [
                'expires' => $this->backup->expires_at->diffForHumans(),
            ])))
            ->salutation(' ');

        return $mail;
    }
}
