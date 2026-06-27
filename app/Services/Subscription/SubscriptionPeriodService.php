<?php

namespace App\Services\Subscription;

use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Models\Management\SubscriptionPeriod;
use App\Services\Ai\SpaceAiUsageService;
use App\Services\Space\SpaceUsageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maintains the persistent billing-period history for a space. Each period is a
 * contiguous run on a single plan; it opens when a plan activates and closes —
 * with usage rolled up — when the plan switches, the cycle renews, or the
 * subscription lapses. Idempotent: calling {@see reconcile()} repeatedly never
 * creates duplicate or spurious periods.
 */
class SubscriptionPeriodService
{
    public function __construct(
        private readonly SpaceUsageService $usage,
        private readonly SpaceAiUsageService $aiUsage,
    ) {}

    public function reconcile(Space $space): void
    {
        $subscription = $space->resolveCurrentSubscription();
        $open = $space->subscriptionPeriods()->open()->latest('started_at')->first();

        // No live plan: close any open period and stop.
        if (! $subscription || ! $subscription->isActive()) {
            if ($open) {
                $reason = $subscription && $subscription->status === 'cancelled' ? 'cancelled' : 'expired';
                $this->close($space, $open, $reason);
            }

            return;
        }

        // Active, nothing open yet: start the first period.
        if (! $open) {
            $this->open($space, $subscription, 'created');

            return;
        }

        // Plan switched: close the old run, open a new one.
        if ($open->plan_id !== $subscription->plan_id) {
            $newPrice = (float) ($subscription->plan?->price ?? 0);
            $reason = $newPrice >= (float) $open->price ? 'upgraded' : 'downgraded';
            $this->rollover($space, $open, $subscription, $reason);

            return;
        }

        // Same plan, the billing cycle rolled over (renews_at advanced).
        if ($this->cycleRolledOver($open, $subscription)) {
            $this->rollover($space, $open, $subscription, 'renewed');
        }

        // Otherwise the open period is still current — nothing to do.
    }

    private function cycleRolledOver(SubscriptionPeriod $open, Subscription $subscription): bool
    {
        if ($subscription->renews_at === null) {
            return false;
        }

        // First time we learn of a renewal anchor for an open period, or the
        // anchor moved forward (a successful renewal opened a fresh cycle).
        return $open->renews_at === null
            || $subscription->renews_at->gt($open->renews_at);
    }

    /** Close the current period and immediately open a fresh one for the same subscription. */
    private function rollover(Space $space, SubscriptionPeriod $open, Subscription $subscription, string $reason): void
    {
        // Roll up usage (may do a live AI fetch) before opening the transaction.
        $rollup = $this->rollup($space, $open, $reason);

        DB::transaction(function () use ($space, $open, $subscription, $rollup) {
            $open->update($rollup);
            $this->open($space, $subscription, 'created', startedAt: $open->ended_at ?? now());
        });
    }

    private function open(Space $space, Subscription $subscription, string $reason, ?Carbon $startedAt = null): SubscriptionPeriod
    {
        return $space->subscriptionPeriods()->create([
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'plan_name' => $subscription->plan?->getTranslatedName() ?? $subscription->name ?? 'Unknown',
            'quotas' => $subscription->effectiveQuotas(),
            'price' => $subscription->plan?->price ?? 0,
            'billing_period' => $subscription->plan?->period ?? 'month',
            'status' => $subscription->status,
            'started_at' => $startedAt ?? now(),
            'renews_at' => $subscription->renews_at,
            'ended_at' => null,
            'close_reason' => null,
        ]);
    }

    /** Close a period in place, rolling up the usage consumed during its window. */
    private function close(Space $space, SubscriptionPeriod $period, string $reason): void
    {
        $period->update($this->rollup($space, $period, $reason));
    }

    /**
     * Compute a closing period's usage rollup. Kept free of DB writes so a slow
     * live AI fetch never holds a transaction open. Each metric degrades to null
     * if it cannot be computed (e.g. the space DB or OpenRouter is unreachable)
     * so closing a period — often triggered from a webhook — never fails.
     *
     * @return array<string, mixed>
     */
    private function rollup(Space $space, SubscriptionPeriod $period, string $reason): array
    {
        $endedAt = now();
        $start = $period->started_at ?? $period->created_at ?? $endedAt;

        return [
            'ended_at' => $endedAt,
            'close_reason' => $reason,
            'storage_bytes' => $this->safeMetric(fn () => (int) round($this->usage->rawStorage($space))),
            'traffic_bytes' => $this->safeMetric(fn () => (int) round($this->usage->rawTraffic($space, $start, $endedAt))),
            'requests_count' => $this->safeMetric(fn () => (int) round($this->usage->rawRequests($space, $start, $endedAt))),
            'ai_spend_usd' => $this->safeMetric(fn () => $this->aiUsage->spendForWindow($space, $start, $endedAt)),
        ];
    }

    /**
     * Run a metric computation, returning null (and logging) on any failure so a
     * single unavailable dimension never blocks the period close.
     */
    private function safeMetric(callable $compute): int|float|null
    {
        try {
            return $compute();
        } catch (\Throwable $e) {
            Log::warning('Failed to roll up a usage metric on period close', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
