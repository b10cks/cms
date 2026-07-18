<?php

namespace App\Services\Subscription;

use App\Models\Management\Space;
use App\Models\Management\SpaceUsageAlert;
use App\Models\User;
use App\Notifications\Space\UsageThresholdNotification;
use App\Services\Auth\AuthorizationService;
use App\Services\Space\Dto\UsageMetricDto;
use App\Services\Space\SpaceUsageService;
use Illuminate\Support\Facades\Log;

/**
 * Soft-quota watchdog. Compares a space's live usage against its plan quotas
 * and notifies the billing-relevant users when a threshold is crossed. Quotas
 * are soft: nothing is ever blocked here — this is purely the early warning.
 *
 * Idempotent per allowance window: the unique space/metric/threshold/period row
 * in space_usage_alerts guarantees at most one notification per crossing.
 */
class UsageAlertService
{
    /** Thresholds (percent of quota) that trigger a notification, ascending. */
    public const THRESHOLDS = [80, 100];

    public function __construct(
        private readonly SpaceUsageService $usage,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Check one space and send notifications for newly crossed thresholds.
     * Returns the number of notifications sent.
     */
    public function check(Space $space): int
    {
        $subscription = $space->resolveCurrentSubscription();

        if (! $subscription || ! $subscription->isActive() || $subscription->effectiveQuotas() === []) {
            return 0;
        }

        $usage = $this->usage->forSpace($space);
        $periodKey = now()->format('Y-m');
        $sent = 0;

        foreach (['storage', 'traffic', 'requests', 'ai'] as $key) {
            $metric = $usage[$key];

            if ($metric->unlimited() || ! $metric->available || $metric->limit <= 0) {
                continue;
            }

            $crossed = array_filter(
                self::THRESHOLDS,
                fn (int $threshold) => $metric->percentage() >= $threshold,
            );

            if ($crossed === []) {
                continue;
            }

            // Record every crossed threshold, but only notify for the highest
            // new one — crossing 80 and 100 between two runs sends one mail.
            $new = [];
            foreach ($crossed as $threshold) {
                $alert = SpaceUsageAlert::firstOrCreate([
                    'space_id' => $space->id,
                    'metric' => $key,
                    'threshold' => $threshold,
                    'period_key' => $periodKey,
                ]);

                if ($alert->wasRecentlyCreated) {
                    $new[] = $threshold;
                }
            }

            if ($new === []) {
                continue;
            }

            $this->notify($space, $metric, max($new));
            $sent++;
        }

        return $sent;
    }

    private function notify(Space $space, UsageMetricDto $metric, int $threshold): void
    {
        $notification = new UsageThresholdNotification(
            space: ['id' => $space->id, 'name' => $space->name],
            metric: $metric->key,
            threshold: $threshold,
            percentage: $metric->percentage(),
            used: $this->format($metric->unit, $metric->used),
            limit: $this->format($metric->unit, (float) $metric->limit),
        );

        foreach ($this->billingRecipients($space) as $user) {
            try {
                $user->notify($notification);
            } catch (\Throwable $e) {
                Log::warning('Failed to send usage alert', [
                    'space_id' => $space->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Everyone who can see billing for the space — mirrors the `viewBilling`
     * policy so the alert lands with the people who can act on it.
     *
     * @return array<int, User>
     */
    private function billingRecipients(Space $space): array
    {
        return $space->users
            ->filter(fn (User $user) => $this->authorization->canInSpace($user, $space, 'space.billing.view'))
            ->values()
            ->all();
    }

    private function format(string $unit, float $value): string
    {
        return match ($unit) {
            'bytes' => $this->formatBytes($value),
            'usd' => '$'.number_format($value, 2),
            default => number_format($value),
        };
    }

    private function formatBytes(float $bytes): string
    {
        $GB = 1024 ** 3;
        $MB = 1024 ** 2;

        return $bytes >= $GB
            ? rtrim(rtrim(number_format($bytes / $GB, 1), '0'), '.').' GB'
            : rtrim(rtrim(number_format($bytes / $MB, 1), '0'), '.').' MB';
    }
}
