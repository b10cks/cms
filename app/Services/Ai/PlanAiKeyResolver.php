<?php

namespace App\Services\Ai;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;

/**
 * Resolves the OpenRouter key a space is entitled to from its current
 * subscription and the plan's definition.
 *
 * Eligibility:
 *  - The space must have a currently active subscription (active/on_trial).
 *  - Paid plans additionally require a live, paid LemonSqueezy subscription
 *    (a linked lemon_squeezy_id in an active state). Free plans are eligible
 *    while active.
 *  - The plan's quota must define an AI credit. `quotas.aiCredit` is the USD
 *    spend limit. `quotas === null` denotes an unlimited tier (e.g. Enterprise).
 */
class PlanAiKeyResolver
{
    public function resolve(Space $space): AiKeySpec
    {
        $subscription = $space->resolveCurrentSubscription();

        if (! $subscription instanceof Subscription || ! $subscription->isActive()) {
            return AiKeySpec::ineligible();
        }

        $plan = $subscription->plan;

        if (! $plan instanceof Plan) {
            return AiKeySpec::ineligible();
        }

        // Paid plans must be backed by a live, paid LemonSqueezy subscription.
        if (! $plan->is_free && ! $subscription->isPaid()) {
            return AiKeySpec::ineligible();
        }

        $reset = config('ai.drivers.openrouter.key_reset', 'monthly');

        // A subscription may snapshot its own quotas at purchase time; fall back
        // to the plan definition. Note: null is meaningful (unlimited) and must
        // not be flattened to an empty array as effectiveQuotas() would do.
        $quotas = $subscription->quotas ?? $plan->quotas;

        if ($quotas === null) {
            return new AiKeySpec(eligible: true, unlimited: true, limitReset: $reset);
        }

        $aiCredit = $quotas['aiCredit'] ?? null;

        if ($aiCredit === null || (float) $aiCredit <= 0) {
            // Plan includes no AI allowance.
            return AiKeySpec::ineligible();
        }

        return new AiKeySpec(
            eligible: true,
            limit: (float) $aiCredit,
            limitReset: $reset,
        );
    }
}
