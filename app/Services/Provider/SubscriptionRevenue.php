<?php

namespace App\Services\Provider;

use App\Enums\SubscriptionStatus;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use Illuminate\Support\Collection;

/** Revenue and billing flags of a space's current subscription. */
class SubscriptionRevenue
{
    /**
     * Current subscription per space id, for the given spaces or, with null,
     * for every space (no id list, so it stays one plain query on big instances).
     *
     * @param  array<int, string>|null  $spaceIds
     * @return Collection<string, Subscription>
     */
    public function currentBySpace(?array $spaceIds = null): Collection
    {
        if ($spaceIds === []) {
            return collect();
        }

        return Subscription::query()
            ->with('plan')
            ->when($spaceIds !== null, fn ($query) => $query->whereIn('space_id', $spaceIds))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('space_id')
            ->map(fn (Collection $subscriptions) => Space::pickCurrentSubscription($subscriptions))
            ->filter();
    }

    /** Monthly recurring revenue: paid, entitled, not on trial; yearly prices count a twelfth. */
    public function monthly(Subscription $subscription): float
    {
        if (! $subscription->isPaid() || $subscription->status === SubscriptionStatus::OnTrial->value) {
            return 0.0;
        }

        $interval = $subscription->billing_interval ?: ($subscription->plan?->period ?? 'month');
        $price = (float) ($subscription->plan?->priceForInterval($interval) ?? 0);

        return $interval === 'year' ? $price / 12 : $price;
    }

    public function isTrial(Subscription $subscription): bool
    {
        return $subscription->status === SubscriptionStatus::OnTrial->value;
    }

    public function trialEnded(Subscription $subscription): bool
    {
        return $subscription->trial_ends_at !== null
            && $subscription->trial_ends_at->isPast()
            && ! $subscription->isActive();
    }

    public function paymentFailed(Subscription $subscription): bool
    {
        return in_array($subscription->status, [SubscriptionStatus::PastDue->value, SubscriptionStatus::Unpaid->value], true);
    }
}
