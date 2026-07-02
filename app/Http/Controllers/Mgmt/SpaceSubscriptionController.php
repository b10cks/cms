<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\SubscriptionStatus;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\SubscriptionResource;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class SpaceSubscriptionController extends Controller
{
    public function __construct(private LemonSqueezyService $ls) {}

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

        $request->validate([
            'plan_id' => 'required|string|exists:plans,id',
        ]);

        $plan = Plan::findOrFail($request->plan_id);

        if (! empty($plan->contact_url)) {
            return response()->json(['message' => 'This plan requires contacting us directly. Please use the provided contact link.'], 422);
        }

        if ($plan->is_free) {
            // Switch to free plan: cancel existing paid subscription if any
            $existing = Subscription::where('space_id', $space->id)->active()->first();
            if ($existing && ! $existing->isFree() && $existing->lemon_squeezy_id) {
                try {
                    $this->ls->cancelSubscription($existing->lemon_squeezy_id);
                } catch (\Throwable $e) {
                    Log::error('Failed to cancel LS subscription for downgrade', [
                        'space_id' => $space->id,
                        'ls_id' => $existing->lemon_squeezy_id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $existing->update(['status' => SubscriptionStatus::Cancelled->value, 'ends_at' => now()]);
            }

            // Create or update free subscription
            Subscription::updateOrCreate(
                ['space_id' => $space->id, 'plan_id' => $plan->id],
                [
                    'name' => $plan->getTranslatedName() ?? 'Free',
                    'status' => SubscriptionStatus::Active->value,
                    'lemon_squeezy_id' => null,
                    'ls_customer_id' => null,
                    'variant_id' => '',
                    'product_id' => '',
                    'quantity' => 1,
                    'quotas' => $plan->quotas,
                ]
            );

            return response()->json(['checkout_url' => null]);
        }

        if (! $this->ls->isConfigured()) {
            return response()->json(['message' => 'Payment provider not configured.'], 503);
        }

        if (empty($plan->ls_variant_id)) {
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

        // Check if space already has an active subscription on this plan
        $existingActive = Subscription::where('space_id', $space->id)
            ->where('plan_id', $plan->id)
            ->active()
            ->first();

        if ($existingActive) {
            return response()->json(['message' => 'Already subscribed to this plan.'], 422);
        }

        // If upgrading from existing paid plan, use LS plan change instead of new checkout
        $existingPaid = Subscription::where('space_id', $space->id)
            ->active()
            ->whereNotNull('lemon_squeezy_id')
            ->first();

        if ($existingPaid && $existingPaid->lemon_squeezy_id) {
            try {
                $this->ls->changeSubscriptionVariant($existingPaid->lemon_squeezy_id, $plan->ls_variant_id);
                $existingPaid->update([
                    'plan_id' => $plan->id,
                    'variant_id' => $plan->ls_variant_id,
                    'product_id' => $plan->ls_product_id ?? $existingPaid->product_id,
                    'quotas' => $plan->quotas,
                    'name' => $plan->getTranslatedName() ?? 'Subscription',
                ]);

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
                'variant_id' => $plan->ls_variant_id,
                'product_id' => $plan->ls_product_id ?? '',
                'quantity' => 1,
                'quotas' => $plan->quotas,
            ]
        );

        try {
            $user = auth()->user();
            $redirectUrl = config('app.url')."/{$space->id}/settings/subscription";

            $checkout = $this->ls->createCheckout(
                variantId: $plan->ls_variant_id,
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

        if (! $plan || empty($plan->ls_variant_id)) {
            return response()->json(['message' => 'The pending plan is not available for purchase.'], 422);
        }

        if (! $this->ls->isConfigured()) {
            return response()->json(['message' => 'Payment provider not configured.'], 503);
        }

        try {
            $user = auth()->user();
            $redirectUrl = config('app.url')."/{$space->id}/settings/subscription";

            $checkout = $this->ls->createCheckout(
                variantId: $plan->ls_variant_id,
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
     * Cancel the active subscription at period end.
     */
    public function cancel(Space $space): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        $subscription = Subscription::where('space_id', $space->id)
            ->active()
            ->whereNotNull('lemon_squeezy_id')
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active paid subscription found.'], 404);
        }

        if (! $this->ls->isConfigured()) {
            return response()->json(['message' => 'Payment provider not configured.'], 503);
        }

        try {
            $this->ls->cancelSubscription($subscription->lemon_squeezy_id);
            $subscription->update(['status' => SubscriptionStatus::Cancelled->value]);

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
}
