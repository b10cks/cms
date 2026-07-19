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
 * Someone with billing rights (typically an agency) proposed a plan for a
 * space and asks the recipient to complete the payment, making them the
 * billing owner (agency flow).
 *
 * Only scalar data is passed in (never models) — the notification queues
 * against the management DB and must not depend on request-time state.
 */
class PaymentRequestedNotification extends Notification implements ShouldQueue
{
    use DeliversInApp;
    use Queueable;

    /**
     * @param  array{id: string, name: string}  $space
     * @param  array{name: string, price: string, interval: string}  $plan
     * @param  string  $requester  display name of who asked for the payment
     */
    public function __construct(
        protected array $space,
        protected array $plan,
        protected string $requester,
    ) {}

    public function notificationType(): NotificationType
    {
        return NotificationType::PaymentRequested;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'space' => $this->space,
            'plan' => $this->plan,
            'requester' => $this->requester,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf(
            '%s/%s/settings/subscription',
            rtrim((string) config('app.frontend_url'), '/'),
            $this->space['id'],
        );

        $params = [
            'space' => $this->space['name'],
            'plan' => $this->plan['name'],
            'price' => $this->plan['price'],
            'interval' => __("notifications.billingIntervals.{$this->plan['interval']}"),
            'requester' => $this->requester,
        ];

        return (new MailMessage)
            ->subject(__('notifications.paymentRequested.subject', $params))
            ->greeting(__('notifications.greeting'))
            ->line(new HtmlString(__('notifications.paymentRequested.intro', [
                ...$params,
                'space' => e($this->space['name']),
                'requester' => e($this->requester),
            ])))
            ->line(__('notifications.paymentRequested.detail', $params))
            ->action(__('notifications.paymentRequested.action'), $url)
            ->line(__('notifications.paymentRequested.outro'))
            ->salutation(' ');
    }
}
