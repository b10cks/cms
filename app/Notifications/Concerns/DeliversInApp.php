<?php

namespace App\Notifications\Concerns;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Shared delivery rules for in-app notifications.
 *
 * In-app (database + broadcast) is the primary channel and is delivered
 * instantly via Reverb. Email is a deferred fallback that is only sent when
 * the recipient did not see the notification in-app in time — "email only when
 * needed". Recipients that are not registered users (e.g. an invite addressed
 * to a bare email) receive email immediately, since they have no inbox.
 *
 * Using classes must implement {@see NotificationType()} and {@see toArray()}.
 */
trait DeliversInApp
{
    abstract public function notificationType(): NotificationType;

    /**
     * The payload stored for the database channel and sent over broadcast.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(object $notifiable): array;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            ? ['database', 'broadcast', 'mail']
            : ['mail'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->notificationType()->value;
    }

    public function broadcastType(): string
    {
        return $this->notificationType()->value;
    }

    /**
     * Defer the email fallback for registered users; non-users are mailed now.
     */
    public function withDelay(object $notifiable, ?string $channel = null): ?\DateTimeInterface
    {
        if ($channel !== 'mail' || ! $notifiable instanceof User) {
            return null;
        }

        return now()->addMinutes((int) config('notifications.mail_delay_minutes', 5));
    }

    /**
     * Send the email only if the in-app notification is still unread — i.e. the
     * user has not already seen it. Other channels always send.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== 'mail' || ! $notifiable instanceof User) {
            return true;
        }

        $record = DatabaseNotification::query()->find($this->id);

        return $record !== null && $record->read_at === null;
    }
}
