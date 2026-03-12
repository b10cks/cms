<?php

namespace App\Actions\Subscription;

use App\Models\Management\Plan;
use App\Models\Management\Space;
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
     *  2. subscription_id   — embedded in customData at checkout time (most reliable)
     *  3. space_id + plan   — fallback via space/variant matching
     */
    public function fromWebhook(array $lsSubscription, ?string $spaceId = null, ?string $subscriptionId = null): Subscription
    {
        $normalized = $this->ls->normalizeSubscription($lsSubscription);
        $lsId = $normalized['lemon_squeezy_id'];
        $spaceId ??= data_get($lsSubscription, 'attributes.custom_data.space_id');
        $subscriptionId ??= data_get($lsSubscription, 'attributes.custom_data.subscription_id');

        if (! $lsId) {
            throw new \RuntimeException('Missing LemonSqueezy subscription ID.');
        }

        return DB::transaction(function () use ($normalized, $lsId, $spaceId, $subscriptionId): Subscription {
            $subscription = $this->resolveLocalSubscription(
                $lsId,
                $normalized['variant_id'],
                $normalized['product_id'],
                $spaceId,
                $subscriptionId,
            );
            $plan = $this->resolvePlan($normalized['variant_id'], $normalized['product_id']);

            $payload = $normalized;

            if ($plan) {
                $payload['plan_id'] = $plan->id;
                $payload['name'] = $plan->getTranslatedName() ?? $normalized['name'];
                $payload['quotas'] = $plan->quotas;
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

                $spaceId = data_get($lsSub, 'attributes.custom_data.space_id');
                $subscriptionId = data_get($lsSub, 'attributes.custom_data.subscription_id');

                $this->fromWebhook($lsSub, $spaceId, $subscriptionId);
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

    private function resolveLocalSubscription(
        string $lemonSqueezyId,
        string $variantId,
        string $productId,
        ?string $spaceId,
        ?string $subscriptionId,
    ): Subscription {
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

        if (! $spaceId) {
            Log::warning('Cannot find space_id for LemonSqueezy subscription', ['ls_id' => $lemonSqueezyId]);
            throw new \RuntimeException("No space_id found for LS subscription {$lemonSqueezyId}");
        }

        $space = Space::findOrFail($spaceId);
        $plan = $this->resolvePlan($variantId, $productId);

        $subscription = Subscription::where('space_id', $space->id)
            ->where('status', 'pending')
            ->whereNull('lemon_squeezy_id')
            ->when($plan, fn ($query) => $query->where('plan_id', $plan->id))
            ->when(! $plan && $variantId !== '', fn ($query) => $query->where('variant_id', $variantId))
            ->latest()
            ->first();

        if ($subscription) {
            return $subscription;
        }

        return new Subscription([
            'space_id' => $space->id,
            'status' => 'pending',
            'quantity' => 1,
        ]);
    }

    private function resolvePlan(?string $variantId, ?string $productId): ?Plan
    {
        if ($variantId) {
            $plan = Plan::where('ls_variant_id', $variantId)->first();
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
                $query->where('status', 'pending')
                    ->orWhereIn('status', ['active', 'on_trial', 'past_due', 'unpaid', 'paused']);
            })
            ->update([
                'status' => 'cancelled',
                'ends_at' => now(),
            ]);
    }
}
