<?php

namespace App\Enums;

/**
 * Canonical set of subscription status values. Kept as the single source of
 * truth so the status strings scattered across billing controllers, the sync
 * action and console commands can't drift via typos.
 *
 * NOTE: the Subscription model's `status` column is intentionally NOT cast to
 * this enum — values originate from LemonSqueezy webhooks and a future/unknown
 * status must not break model hydration. Use `->value` when comparing or
 * assigning, and the helper arrays below for grouped checks.
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case OnTrial = 'on_trial';
    case Paused = 'paused';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Inactive = 'inactive';

    /**
     * Statuses that count as an active, entitlement-granting subscription.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            [self::Active, self::OnTrial],
        );
    }

    /**
     * Statuses that still represent a live (non-terminated) paid subscription,
     * including those in a temporary payment-problem state.
     *
     * @return array<int, string>
     */
    public static function liveValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            [self::Active, self::OnTrial, self::PastDue, self::Unpaid, self::Paused],
        );
    }
}
