<?php

namespace App\Actions\Subscription;

use App\Enums\SubscriptionStatus;

use App\Models\Management\Plan;
use App\Models\Management\Subscription;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncSubscriptionFromLemonSqueezy
{
    public function __construct(private LemonSqueezyService $ls) {}

    /**
     * Sync a subscription from a LemonSqueezy payload (webhook or API response).
     *
     * Lookup priority for the local record:
     *  1. lemon_squeezy_id  — already linked
     *  2. subscription_id   — the pending row embedded in customData at checkout
     *
     * A bare `space_id` in customData is never enough: buyers can set custom
     * data in a checkout URL, which would attach their purchase to any space
     * and cancel that space's current subscription.
     */
    public function fromWebhook(array $lsSubscription, ?string $subscriptionId = null): Subscription
    {
        $normalized = $this->ls->normalizeSubscription($lsSubscription);
        $lsId = $normalized['lemon_squeezy_id'];
        $subscriptionId ??= data_get($lsSubscription, 'attributes.custom_data.subscription_id');

        if (! $lsId) {
            throw new \RuntimeException('Missing LemonSqueezy subscription ID.');
        }

        return DB::transaction(function () use ($normalized, $lsId, $subscriptionId): Subscription {
            $subscription = $this->resolveLocalSubscription(
                $lsId,
                $subscriptionId,
            );
            $plan = $this->resolvePlan($normalized['variant_id'], $normalized['product_id']);

            $payload = $normalized;

            if ($plan) {
                $payload['plan_id'] = $plan->id;
                $payload['name'] = $plan->getTranslatedName() ?? $normalized['name'];
                $payload['billing_interval'] = $plan->ls_variant_id_yearly !== null
                    && $normalized['variant_id'] === $plan->ls_variant_id_yearly ? 'year' : 'month';

                // `quotas` on the subscription is reserved for custom overrides
                // (subsidized deals); plan defaults are resolved at read time via
                // effectiveQuotas(). Only drop an override when the plan changed —
                // a negotiated deal does not survive a plan switch.
                if ($subscription->exists && $subscription->plan_id !== $plan->id) {
                    $payload['quotas'] = null;
                }
            } elseif (! $subscription->exists || empty($subscription->name)) {
                $payload['name'] = $normalized['name'];
            }

            if (($payload['billing_portal_url'] ?? null) === null && $subscription->billing_portal_url) {
                unset($payload['billing_portal_url']);
            }

            $subscription->fill($payload);

            if (! $subscription->billing_portal_url && $subscription->lemon_squeezy_id) {
                $subscription->billing_portal_url = $this->ls->getCustomerPortalUrl($lsId);
            }

            $subscription->save();

            if ($subscription->isActive()) {
                $this->retireConflictingSubscriptions($subscription);
            }

            return $subscription->fresh(['plan', 'space']) ?? $subscription;
        });
    }

    public function fromLemonSqueezyId(string $lemonSqueezyId): Subscription
    {
        $subscription = $this->ls->getSubscription($lemonSqueezyId);

        if (empty($subscription)) {
            throw new \RuntimeException("Unable to fetch LemonSqueezy subscription {$lemonSqueezyId}.");
        }

        return $this->fromWebhook($subscription);
    }

    /**
     * Sync all subscriptions from LemonSqueezy (for cron reconciliation).
     * Uses fromWebhook() so it handles both already-linked subscriptions and
     * pending ones that never received their webhook (e.g. user left checkout).
     */
    public function syncAll(): int
    {
        $lsSubscriptions = $this->ls->listSubscriptions();
        $synced = 0;

        foreach ($lsSubscriptions as $lsSub) {
            try {
                $lsId = $lsSub['id'] ?? null;

                // If not yet locally linked, fetch the full individual record so we
                // get custom_data (the list endpoint may omit it) — that's where the
                // stable subscription_id link lives.
                $localExists = $lsId && Subscription::where('lemon_squeezy_id', $lsId)->exists();
                if (! $localExists && $lsId) {
                    $full = $this->ls->getSubscription($lsId);
                    if (! empty($full)) {
                        $lsSub = $full;
                    }
                }

                $subscriptionId = data_get($lsSub, 'attributes.custom_data.subscription_id');

                $this->fromWebhook($lsSub, $subscriptionId);
                $synced++;
            } catch (\Throwable $e) {
                Log::error('Failed to sync LS subscription', [
                    'ls_id' => $lsSub['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    private function resolveLocalSubscription(string $lemonSqueezyId, ?string $subscriptionId): Subscription
    {
        $subscription = Subscription::where('lemon_squeezy_id', $lemonSqueezyId)->first();

        if ($subscription) {
            return $subscription;
        }

        if ($subscriptionId) {
            $candidate = Subscription::find($subscriptionId);

            if ($candidate && ($candidate->lemon_squeezy_id === null || $candidate->lemon_squeezy_id === $lemonSqueezyId)) {
                return $candidate;
            }
        }

        Log::warning('Cannot link LemonSqueezy subscription to a local subscription', ['ls_id' => $lemonSqueezyId]);

        throw new \RuntimeException("No local subscription found for LS subscription {$lemonSqueezyId}");
    }

    private function resolvePlan(?string $variantId, ?string $productId): ?Plan
    {
        if ($variantId) {
            $plan = Plan::where('ls_variant_id', $variantId)
                ->orWhere('ls_variant_id_yearly', $variantId)
                ->first();
            if ($plan) {
                return $plan;
            }
        }

        if ($productId) {
            return Plan::where('ls_product_id', $productId)->first();
        }

        return null;
    }

    private function retireConflictingSubscriptions(Subscription $subscription): void
    {
        Subscription::query()
            ->where('space_id', $subscription->space_id)
            ->whereKeyNot($subscription->id)
            ->where(function ($query) {
                $query->where('status', SubscriptionStatus::Pending->value)
                    ->orWhereIn('status', SubscriptionStatus::liveValues());
            })
            ->update([
                'status' => SubscriptionStatus::Cancelled->value,
                'ends_at' => now(),
            ]);
    }
}
