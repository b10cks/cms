<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Invite\CreateInvite;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PlanProposalResource;
use App\Models\Management\Plan;
use App\Models\Management\PlanProposal;
use App\Models\Management\Space;
use App\Notifications\Space\PaymentRequestedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment requests (agency flow): a member with billing rights proposes a plan
 * and hands the checkout to a client-side contact, who then becomes the
 * LemonSqueezy customer. The proposal itself never creates a subscription —
 * the invited contact runs the regular checkout, so the billing identity is
 * theirs.
 */
class PlanProposalController extends Controller
{
    /** Grace given to a payment request before it expires, in days. */
    private const EXPIRES_AFTER_DAYS = 14;

    /** The space's current open payment request, if any. */
    public function show(Space $space): JsonResponse
    {
        $this->authorize('viewBilling', $space);

        $proposal = PlanProposal::open()
            ->where('space_id', $space->id)
            ->latest()
            ->first()
            ?->resolveExpiry();

        if ($proposal && $proposal->status !== PlanProposal::STATUS_OPEN) {
            $proposal = null;
        }

        return response()->json([
            'data' => $proposal
                ? new PlanProposalResource($proposal->loadMissing(['plan', 'creator']))
                : null,
        ]);
    }

    /**
     * Create a payment request. Replaces any previous open one. If the invited
     * email is not a member yet, a space invitation with the billing role is
     * sent; members get a payment-request notification instead.
     */
    public function store(Request $request, Space $space, CreateInvite $createInvite): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        $validated = $request->validate([
            'plan_id' => 'required|string|exists:plans,id',
            'interval' => 'sometimes|string|in:month,year',
            'email' => 'required|email',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $interval = $validated['interval'] ?? 'month';

        if (! $plan->is_active || ! $plan->isAvailableForSpace($space)) {
            return response()->json(['message' => 'This plan is not available for this space.'], 422);
        }

        if ($plan->is_free || ! empty($plan->contact_url)) {
            return response()->json(['message' => 'Payment can only be requested for purchasable paid plans.'], 422);
        }

        if ($interval === 'year' && ! $plan->supportsYearly()) {
            return response()->json(['message' => 'This plan does not offer yearly billing.'], 422);
        }

        // One open request per space: a new one supersedes the previous.
        PlanProposal::open()->where('space_id', $space->id)->update([
            'status' => PlanProposal::STATUS_REVOKED,
            'resolved_at' => now(),
        ]);

        $email = strtolower($validated['email']);
        $member = $space->users()->where('email', $email)->first();

        $planName = $plan->getTranslatedName() ?? 'Plan';
        $price = $interval === 'year' && $plan->yearly_price ? $plan->yearly_price : $plan->price;

        $invite = null;
        if (! $member) {
            $invite = $createInvite->execute([
                'email' => $email,
                'role' => 'billing',
                'space_id' => $space->id,
                'message' => __('notifications.paymentRequested.inviteMessage', [
                    'requester' => auth()->user()->name,
                    'space' => $space->name,
                    'plan' => $planName,
                ]),
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            ], auth()->user());
        }

        $proposal = PlanProposal::create([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'billing_interval' => $interval,
            'invited_email' => $email,
            'created_by' => auth()->id(),
            'invite_id' => $invite?->id,
            'status' => PlanProposal::STATUS_OPEN,
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ]);

        $member?->notify(new PaymentRequestedNotification(
            space: ['id' => $space->id, 'name' => $space->name],
            plan: ['name' => $planName, 'price' => (string) $price, 'interval' => $interval],
            requester: auth()->user()->name,
        ));

        return (new PlanProposalResource($proposal->loadMissing(['plan', 'creator'])))
            ->response()
            ->setStatusCode(201);
    }

    /** Revoke the current open payment request. */
    public function destroy(Space $space): JsonResponse
    {
        $this->authorize('manageBilling', $space);

        PlanProposal::open()->where('space_id', $space->id)->update([
            'status' => PlanProposal::STATUS_REVOKED,
            'resolved_at' => now(),
        ]);

        return response()->json(null, 204);
    }
}
