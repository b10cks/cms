<?php

namespace App\Actions\Subscription;

use App\Enums\SubscriptionStatus;
use App\Models\Management\Plan;
use App\Models\Management\Subscription;

/**
 * Activate a subscription for a plan directly, without a LemonSqueezy checkout —
 * free plans, and paid plans in environments where payments are not configured.
 * The single source of truth for the "become subscribed without paying" payload;
 * saving fires the Subscription observer (AI key + period reconciliation).
 */
class ActivateDirectSubscription
{
    public function execute(string $spaceId, Plan $plan): Subscription
    {
        return Subscription::updateOrCreate(
            ['space_id' => $spaceId, 'plan_id' => $plan->id],
            [
                'name' => $plan->getTranslatedName() ?? ($plan->is_free ? 'Free' : 'Subscription'),
                'status' => SubscriptionStatus::Active->value,
                'lemon_squeezy_id' => null,
                'ls_customer_id' => null,
                'variant_id' => $plan->ls_variant_id ?? '',
                'product_id' => $plan->ls_product_id ?? '',
                'quantity' => 1,
                'billing_interval' => 'month',
                // Quotas stay null — plan defaults resolve at read time.
                'quotas' => null,
                'ends_at' => null,
            ]
        );
    }
}
