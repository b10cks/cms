<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Subscription\ActivateDirectSubscription;
use App\Actions\Subscription\SyncSubscriptionFromLemonSqueezy;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\SubscriptionResource;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Services\LemonSqueezy\LemonSqueezyService;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class SpaceSubscriptionController extends Controller
{
    public function __construct(
        private LemonSqueezyService $ls,
        private SyncSubscriptionFromLemonSqueezy $sync,
    ) {}

    /**
     * List all subscriptions for a space.
     */
    public function index(Space $space): ResourceCollection
    {
        $this->authorize('viewBilling', $space);

        $subscriptions = Subscription::where('space_id', $space->id)
            ->with('plan')
            ->latest()
            ->get();

        return SubscriptionResource::collection($subscriptions);
    }

    /**
     * Get the current active subscription (with plan and quotas).
     */
    public function current(Space $space): JsonResponse
    {
        $this->authorize('viewBilling', $space);

        // Return active subscription first; fall back to the most recent pending one
        // so the frontend can show a "payment pending" notice.
        $subscription = Subscription::where('space_id', $space->id)
            ->active()
            ->with('plan')
            ->latest()
            ->first()
            ?? Subscription::where('space_id', $space->id)
                ->where('status', SubscriptionStatus::Pending->value)
                ->with('plan')
                ->latest()
                ->first()
            ?? Subscription::where('space_id', $space->id)
                ->with('plan')
                ->latest()
                ->first();

        if (! $subscription) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => new SubscriptionResource($subscription)]);
    }

    /**
     * Initiate a checkout for a plan. Returns a checkout URL for paid plans.
     */
    public function checkout(Request $request, Space $space): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        $validated = $request->validate([
            'plan_id' => 'required|string|exists:plans,id',
            'interval' => 'sometimes|string|in:month,year',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $interval = $validated['interval'] ?? 'month';

        if (! $plan->is_active || ! $plan->isAvailableForSpace($space)) {
            return response()->json(['message' => 'This plan is not available for this space.'], 422);
        }

        if (! empty($plan->contact_url)) {
            return response()->json(['message' => 'This plan requires contacting us directly. Please use the provided contact link.'], 422);
        }

        if ($plan->is_free) {
            return $this->switchToFreePlan($space, $plan);
        }

        if ($interval === 'year' && ! $plan->supportsYearly()) {
            return response()->json(['message' => 'This plan does not offer yearly billing.'], 422);
        }

        if (! $this->ls->isConfigured()) {
            return response()->json(['message' => 'Payment provider not configured.'], 503);
        }

        $variantId = $plan->variantIdForInterval($interval);

        if (empty($variantId)) {
            return response()->json(['message' => 'This plan is not available for purchase.'], 422);
        }

        // Block if a payment is already pending for any plan.
        // Use POST /subscriptions/reinit to resume a pending checkout for the same plan.
        // The user must cancel the pending subscription first before switching plans.
        $existingPending = Subscription::where('space_id', $space->id)
            ->where('status', SubscriptionStatus::Pending->value)
            ->whereNull('lemon_squeezy_id')
            ->first();

        if ($existingPending) {
            $sameplan = $existingPending->plan_id === $plan->id;

            return response()->json([
                'message' => $sameplan
                    ? 'A payment for this plan is already pending. Use "Complete payment" to resume it.'
                    : 'A payment for another plan is already pending. Complete or cancel it before switching plans.',
                'use_reinit' => $sameplan,
                'pending_plan_id' => $existingPending->plan_id,
            ], 409);
        }

        // Already on this exact plan and interval, in good standing — nothing to
        // buy. Deliberately limited to the paid-up statuses: past_due/unpaid must
        // be able to re-enter the payment flow, cancelled-with-grace resumes.
        $existingActive = Subscription::where('space_id', $space->id)
            ->where('plan_id', $plan->id)
            ->where('billing_interval', $interval)
            ->whereIn('status', SubscriptionStatus::activeValues())
            ->first();

        if ($existingActive) {
            return response()->json(['message' => 'Already subscribed to this plan.'], 422);
        }

        // If upgrading from existing paid plan (or switching interval), use an LS
        // plan change instead of a new checkout.
        $existingPaid = Subscription::where('space_id', $space->id)
            ->active()
            ->whereNotNull('lemon_squeezy_id')
            ->first();

        if ($existingPaid && $existingPaid->lemon_squeezy_id) {
            try {
                // A cancelled-but-paid-through subscription must be resumed before
                // LemonSqueezy accepts a variant change.
                if ($existingPaid->isCancelledWithGrace()) {
                    $this->ls->resumeSubscription($existingPaid->lemon_squeezy_id);
                }

                $response = $this->ls->changeSubscriptionVariant($existingPaid->lemon_squeezy_id, $variantId);

                if (! empty($response)) {
                    // The LS response is authoritative — in particular for status:
                    // a past_due subscription must not be masked as active just
                    // because the plan switched.
                    $this->sync->fromWebhook($response, $space->id, $existingPaid->id);
                } else {
                    $existingPaid->update([
                        'plan_id' => $plan->id,
                        'variant_id' => $variantId,
                        'product_id' => $plan->ls_product_id ?? $existingPaid->product_id,
                        'billing_interval' => $interval,
                        // Custom quota overrides don't survive a plan switch.
                        'quotas' => $existingPaid->plan_id === $plan->id ? $existingPaid->quotas : null,
                        'name' => $plan->getTranslatedName() ?? 'Subscription',
                    ]);
                }

                return response()->json(['checkout_url' => null, 'upgraded' => true]);
            } catch (\Throwable $e) {
                Log::error('Failed to change LS subscription variant', [
                    'space_id' => $space->id,
                    'ls_id' => $existingPaid->lemon_squeezy_id,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to create new checkout
            }
        }

        // Create or find the pending subscription before calling LS so we can embed
        // its local ID in customData — this is the stable key used to link the LS
        // subscription back to this record during webhook processing and CLI sync.
        $pending = Subscription::updateOrCreate(
            ['space_id' => $space->id, 'status' => SubscriptionStatus::Pending->value, 'plan_id' => $plan->id],
            [
                'name' => $plan->getTranslatedName() ?? 'Subscription',
                'lemon_squeezy_id' => null,
                'variant_id' => $variantId,
                'product_id' => $plan->ls_product_id ?? '',
                'quantity' => 1,
                'billing_interval' => $interval,
            ]
        );

        try {
            $user = auth()->user();
            $redirectUrl = config('app.url')."/{$space->id}/settings/subscription";

            $checkout = $this->ls->createCheckout(
                variantId: $variantId,
                email: $user->email,
                name: $user->display_name ?? $user->name,
                customData: ['space_id' => $space->id, 'plan_id' => $plan->id, 'subscription_id' => $pending->id],
                redirectUrl: $redirectUrl,
            );

            return response()->json(['checkout_url' => $checkout['checkout_url']]);
        } catch (\Throwable $e) {
            Log::error('Failed to create LS checkout', [
                'space_id' => $space->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to initiate checkout. Please try again.'], 500);
        }
    }

    /**
     * Re-initiate the checkout flow for a pending subscription.
     * No body required — the pending subscription and its plan are resolved automatically.
     */
    public function reinit(Space $space): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        $pending = Subscription::where('space_id', $space->id)
            ->where('status', SubscriptionStatus::Pending->value)
            ->whereNull('lemon_squeezy_id')
            ->with('plan')
            ->latest()
            ->first();

        if (! $pending) {
            return response()->json(['message' => 'No pending subscription found for this space.'], 404);
        }

        $plan = $pending->plan;
        // Prefer the variant chosen at checkout time (it encodes the interval).
        $variantId = $pending->variant_id ?: $plan?->variantIdForInterval($pending->billing_interval);

        if (! $plan || empty($variantId)) {
            return response()->json(['message' => 'The pending plan is not available for purchase.'], 422);
        }

        if (! $this->ls->isConfigured()) {
            return response()->json(['message' => 'Payment provider not configured.'], 503);
        }

        try {
            $user = auth()->user();
            $redirectUrl = config('app.url')."/{$space->id}/settings/subscription";

            $checkout = $this->ls->createCheckout(
                variantId: $variantId,
                email: $user->email,
                name: $user->display_name ?? $user->name ?? $user->email,
                customData: ['space_id' => $space->id, 'plan_id' => $plan->id, 'subscription_id' => $pending->id],
                redirectUrl: $redirectUrl,
            );

            return response()->json(['checkout_url' => $checkout['checkout_url']]);
        } catch (\Throwable $e) {
            Log::error('Failed to reinit LS checkout for pending subscription', [
                'space_id' => $space->id,
                'subscription_id' => $pending->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to initiate payment. Please try again.'], 500);
        }
    }

    /**
     * Discard an abandoned pending checkout. A pending subscription blocks new
     * checkouts (deliberately), so this is the way out when the user changed
     * their mind instead of completing the payment.
     */
    public function discardPending(Space $space): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        // Only checkouts that never reached LemonSqueezy — a pending row with an
        // LS id is mid-webhook and resolves on its own.
        Subscription::where('space_id', $space->id)
            ->where('status', SubscriptionStatus::Pending->value)
            ->whereNull('lemon_squeezy_id')
            ->update([
                'status' => SubscriptionStatus::Expired->value,
                'ends_at' => now(),
            ]);

        return response()->json(null, 204);
    }

    /**
     * Cancel the active subscription at period end. Entitlements continue until
     * the end of the paid period (grace), then the space falls back to Free.
     */
    public function cancel(Space $space): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        $subscription = Subscription::where('space_id', $space->id)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->whereNotNull('lemon_squeezy_id')
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active paid subscription found.'], 404);
        }

        if (! $this->ls->isConfigured()) {
            return response()->json(['message' => 'Payment provider not configured.'], 503);
        }

        try {
            $response = $this->cancelAtLemonSqueezy($subscription);
            $this->syncCancellation($subscription, $response);

            return response()->json(['message' => 'Subscription cancelled. Access continues until the end of the billing period.']);
        } catch (\Throwable $e) {
            Log::error('Failed to cancel LS subscription', [
                'space_id' => $space->id,
                'ls_id' => $subscription->lemon_squeezy_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to cancel subscription. Please try again.'], 500);
        }
    }

    /**
     * Resume a subscription that was cancelled but is still within its paid
     * period, so it renews normally again.
     */
    public function resume(Space $space): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        $subscription = Subscription::where('space_id', $space->id)
            ->where('status', SubscriptionStatus::Cancelled->value)
            ->where('ends_at', '>', now())
            ->whereNotNull('lemon_squeezy_id')
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No resumable subscription found.'], 404);
        }

        if (! $this->ls->isConfigured()) {
            return response()->json(['message' => 'Payment provider not configured.'], 503);
        }

        try {
            $response = $this->ls->resumeSubscription($subscription->lemon_squeezy_id);

            if (! empty($response)) {
                $this->sync->fromWebhook($response, $space->id, $subscription->id);
            } else {
                $subscription->update(['status' => SubscriptionStatus::Active->value, 'ends_at' => null]);
            }

            return response()->json(['message' => 'Subscription resumed.']);
        } catch (\Throwable $e) {
            Log::error('Failed to resume LS subscription', [
                'space_id' => $space->id,
                'ls_id' => $subscription->lemon_squeezy_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to resume subscription. Please try again.'], 500);
        }
    }

    /**
     * Downgrade to the free plan. A live paid subscription is cancelled at
     * period end and keeps its entitlements until then; the free plan is
     * auto-enrolled once it lapses. Without a paid subscription the free plan
     * activates immediately.
     */
    private function switchToFreePlan(Space $space, Plan $plan): JsonResponse
    {
        // Already cancelled but paid through the period: keep the remaining
        // grace — reconciliation auto-enrolls Free once it lapses. Creating the
        // free subscription now would forfeit days the user already paid for.
        $inGrace = Subscription::where('space_id', $space->id)
            ->where('status', SubscriptionStatus::Cancelled->value)
            ->where('ends_at', '>', now())
            ->exists();

        if ($inGrace) {
            return response()->json([
                'checkout_url' => null,
                'scheduled' => true,
                'message' => 'Your paid plan stays active until the end of the billing period, then the space switches to Free.',
            ]);
        }

        $existing = Subscription::where('space_id', $space->id)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->whereNotNull('lemon_squeezy_id')
            ->first();

        if ($existing) {
            try {
                $response = $this->cancelAtLemonSqueezy($existing);
                $this->syncCancellation($existing, $response);

                return response()->json([
                    'checkout_url' => null,
                    'scheduled' => true,
                    'message' => 'Your paid plan stays active until the end of the billing period, then the space switches to Free.',
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to cancel LS subscription for downgrade', [
                    'space_id' => $space->id,
                    'ls_id' => $existing->lemon_squeezy_id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['message' => 'Failed to schedule the downgrade. Please try again.'], 500);
            }
        }

        app(ActivateDirectSubscription::class)->execute($space->id, $plan);

        return response()->json(['checkout_url' => null]);
    }

    /**
     * Cancel at LemonSqueezy, treating a client error (the subscription is
     * already terminal on their side, e.g. unpaid or expired) as "nothing to
     * cancel remotely" so the local downgrade still proceeds.
     */
    private function cancelAtLemonSqueezy(Subscription $subscription): array
    {
        try {
            return $this->ls->cancelSubscription($subscription->lemon_squeezy_id);
        } catch (ClientException $e) {
            Log::warning('LS refused cancellation; treating subscription as already terminal', [
                'space_id' => $subscription->space_id,
                'ls_id' => $subscription->lemon_squeezy_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Reflect an LS cancellation locally. Prefers the authoritative LS response
     * (it carries the period-end `ends_at`); falls back to marking the record
     * cancelled with the renewal date as the grace boundary. A subscription that
     * was not entitlement-granting (unpaid, paused) gets no grace.
     */
    private function syncCancellation(Subscription $subscription, array $lsResponse): void
    {
        if (! empty($lsResponse)) {
            $this->sync->fromWebhook($lsResponse, $subscription->space_id, $subscription->id);

            return;
        }

        $subscription->update([
            'status' => SubscriptionStatus::Cancelled->value,
            'ends_at' => $subscription->isActive()
                ? ($subscription->ends_at ?? $subscription->renews_at ?? now())
                : now(),
        ]);
    }
}
