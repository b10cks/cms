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
 * A space crossed a soft-quota threshold on one of its usage dimensions.
 * Quotas are soft: nothing is blocked, this is the heads-up to upgrade.
 *
 * Only scalar data is passed in (never models) — the notification queues
 * against the management DB and must not depend on request-time state.
 */
class UsageThresholdNotification extends Notification implements ShouldQueue
{
    use DeliversInApp;
    use Queueable;

    /**
     * @param  array{id: string, name: string}  $space
     * @param  string  $metric  storage | traffic | requests | ai
     * @param  int  $threshold  the crossed threshold in percent (80, 100)
     * @param  int  $percentage  actual consumption in percent at check time
     * @param  string  $used  human-readable usage, e.g. "4.2 GB"
     * @param  string  $limit  human-readable quota, e.g. "5 GB"
     */
    public function __construct(
        protected array $space,
        protected string $metric,
        protected int $threshold,
        protected int $percentage,
        protected string $used,
        protected string $limit,
    ) {}

    public function notificationType(): NotificationType
    {
        return $this->threshold >= 100
            ? NotificationType::UsageExceeded
            : NotificationType::UsageWarning;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'space' => $this->space,
            'metric' => $this->metric,
            'threshold' => $this->threshold,
            'percentage' => $this->percentage,
            'used' => $this->used,
            'limit' => $this->limit,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->threshold >= 100 ? 'usageExceeded' : 'usageWarning';
        $url = sprintf(
            '%s/%s/settings/subscription',
            rtrim((string) config('app.frontend_url'), '/'),
            $this->space['id'],
        );

        $params = [
            'space' => $this->space['name'],
            'metric' => __("notifications.usageMetrics.{$this->metric}"),
            'percentage' => $this->percentage,
            'used' => $this->used,
            'limit' => $this->limit,
        ];

        return (new MailMessage)
            ->subject(__("notifications.{$key}.subject", $params))
            ->greeting(__('notifications.greeting'))
            ->line(new HtmlString(__("notifications.{$key}.intro", [
                ...$params,
                'space' => e($this->space['name']),
            ])))
            ->line(__("notifications.{$key}.detail", $params))
            ->action(__("notifications.{$key}.action"), $url)
            ->line(__("notifications.{$key}.outro"))
            ->salutation(' ');
    }
}
