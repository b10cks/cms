<?php

namespace App\Notifications\Space;

use App\Enums\NotificationType;
use App\Notifications\Concerns\DeliversInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Sent to a user who was @-mentioned in a content comment.
 *
 * Only plain scalar/array data is carried so the queued payload never depends
 * on the dynamically-resolved space database connection.
 */
class MentionNotification extends Notification implements ShouldQueue
{
    use DeliversInApp;
    use Queueable;

    /**
     * @param  array{id: string, name: string}  $space
     * @param  array{id: string, name: ?string}  $content
     * @param  array{id: string, display_name: string}  $author
     */
    public function __construct(
        protected array $space,
        protected array $content,
        protected array $author,
        protected ?string $itemId,
        protected ?string $field,
        protected string $excerpt,
    ) {}

    public function notificationType(): NotificationType
    {
        return NotificationType::CommentMention;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'space' => $this->space,
            'content' => $this->content,
            'item_id' => $this->itemId,
            'field' => $this->field,
            'author' => $this->author,
            'excerpt' => $this->excerpt,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf(
            '%s/%s/content/%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $this->space['id'],
            $this->content['id'],
        );

        return (new MailMessage)
            ->subject(__('notifications.commentMention.subject', [
                'author' => $this->author['display_name'],
            ]))
            ->greeting(__('notifications.greeting'))
            ->line(new HtmlString(__('notifications.commentMention.intro', [
                'author' => $this->author['display_name'],
                'content' => $this->content['name'] ?? $this->space['name'],
            ])))
            ->line(new HtmlString('<blockquote style="border-left: 4px solid #ccc; padding-left: 16px; margin-left: 0;">'.e($this->excerpt).'</blockquote>'))
            ->action(__('notifications.commentMention.action'), $url)
            ->salutation(' ');
    }
}
