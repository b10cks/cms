<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\SubscriptionStatus;

use App\Actions\Space\CreateSpace;
use App\Actions\Subscription\ActivateDirectSubscription;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\SpaceFilter;
use App\Http\Requests\Space\CreateSpaceRequest;
use App\Http\Requests\Space\UpdateSpaceRequest;
use App\Http\Resources\Management\SpaceResource;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Models\Management\Team;
use App\Services\Auth\AuthorizationService;
use App\Support\EditionGate;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SpaceController extends Controller
{
    /**
     * Display a listing of the spaces.
     */
    public function index(Request $request): ResourceCollection
    {
        $this->authorize('viewAny', Space::class);
        $user = auth()->user();
        $accessibleSpaceIds = $user->is_root
            ? []
            : app(AuthorizationService::class)->accessibleSpaceIds($user);

        $spaces = Space::filter(SpaceFilter::fromRequest($request))
            ->when(!$user->is_root, function ($query) use ($accessibleSpaceIds) {
                $query->whereIn('spaces.id', $accessibleSpaceIds);
            })
            ->with([
                'subscriptions' => fn($query) => $query->with('plan')->latest('created_at'),
            ])
            ->withCount(['users'])
            ->paginate($this->perPage($request, 20, 1000));

        return SpaceResource::collection($spaces);
    }

    /**
     * Store a newly created space in storage.
     */
    public function store(
        CreateSpaceRequest $request,
        CreateSpace $action,
        LemonSqueezyService $ls,
        AuthorizationService $authorizationService,
    ): SpaceResource {
        $validated = $request->validated();
        if (!empty($validated['team_id'])) {
            $team = Team::query()->findOrFail($validated['team_id']);
            abort_unless($authorizationService->canInTeam(auth()->user(), $team, 'team.spaces.create'), 403);
        }
        $planId = $validated['plan_id'] ?? null;
        $interval = $validated['billing_interval'] ?? 'month';
        unset($validated['plan_id'], $validated['billing_interval']);

        // Validation guarantees the plan is active and public. Resolve the
        // purchasability of a paid plan BEFORE creating the space — a paid plan
        // whose LS variant is missing must fail loudly (mirroring checkout()),
        // not silently grant an unpaid Active subscription.
        $plan = $planId ? Plan::findOrFail($planId) : null;

        // Self-hosted installs skip the plan step in the UI; attach the free
        // plan so every space still carries a subscription (matching what
        // subscriptions:backfill-free produces at install time).
        if (! $plan && ! EditionGate::billingEnabled()) {
            $plan = Plan::query()
                ->where('is_free', true)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
        }

        $variantId = null;

        if ($plan && ! $plan->is_free && $ls->isConfigured()) {
            if ($interval === 'year' && ! $plan->supportsYearly()) {
                throw ValidationException::withMessages([
                    'billing_interval' => 'This plan does not offer yearly billing.',
                ]);
            }

            $variantId = $plan->variantIdForInterval($interval);

            if (empty($variantId)) {
                throw ValidationException::withMessages([
                    'plan_id' => 'This plan is not available for purchase.',
                ]);
            }
        }

        $space = $action->execute($validated, auth()->user());

        $checkoutUrl = null;

        if ($plan) {
            if ($variantId === null) {
                // Free plan, or payments not configured — activate immediately.
                app(ActivateDirectSubscription::class)->execute($space->id, $plan);
            } else {
                // Create pending subscription first so its ID can be embedded in
                // checkout customData — the stable link used by webhook and CLI sync.
                $pending = Subscription::forceCreate([
                    'space_id' => $space->id,
                    'plan_id' => $plan->id,
                    'name' => $plan->getTranslatedName() ?? 'Subscription',
                    'status' => SubscriptionStatus::Pending->value,
                    'lemon_squeezy_id' => null,
                    'variant_id' => $variantId,
                    'product_id' => $plan->ls_product_id ?? '',
                    'quantity' => 1,
                    'billing_interval' => $interval,
                ]);

                try {
                    $user = auth()->user();
                    $redirectUrl = config('app.url') . "/{$space->slug}/settings/subscription";

                    $checkout = $ls->createCheckout(
                        variantId: $variantId,
                        email: $user->email,
                        name: $user->display_name ?? $user->name ?? $user->email,
                        customData: ['space_id' => $space->id, 'plan_id' => $plan->id, 'subscription_id' => $pending->id],
                        redirectUrl: $redirectUrl,
                    );

                    $checkoutUrl = $checkout['checkout_url'];
                } catch (\Throwable $e) {
                    Log::error('Failed to create LS checkout on space creation', [
                        'space_id' => $space->id,
                        'plan_id' => $plan->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $space->load([
            'subscriptions' => fn($query) => $query->with('plan')->latest('created_at'),
        ]);

        return (new SpaceResource($space))->additional(['checkout_url' => $checkoutUrl]);
    }

    /**
     * Display the specified space.
     */
    public function show(Space $space): SpaceResource
    {
        $this->authorize('view', $space);
        $space
            ->loadCount(['users'])
            ->load([
                'subscriptions' => fn($query) => $query->with('plan')->latest('created_at'),
            ]);

        return new SpaceResource($space);
    }

    /**
     * Update the specified space in storage.
     */
    public function update(
        UpdateSpaceRequest $request,
        Space $space,
        AuthorizationService $authorizationService,
    ): SpaceResource {
        $this->authorize('update', $space);
        $originalTeamId = $space->team_id;
        $space->fill($request->validated());

        if (!$space->save()) {
            Log::error('Failed to update space', ['space_id' => $space->id]);
            abort(500, 'Failed to update space');
        }

        $space
            ->loadCount(['users'])
            ->load([
                'subscriptions' => fn($query) => $query->with('plan')->latest('created_at'),
            ]);
        if ($originalTeamId !== $space->team_id) {
            if ($originalTeamId) {
                $authorizationService->invalidateTeamTree(Team::query()->findOrFail($originalTeamId));
            }
            if ($space->team_id) {
                $authorizationService->invalidateTeamTree($space->team()->firstOrFail());
            }
        }
        $authorizationService->invalidateSpace($space);

        return new SpaceResource($space);
    }

    /**
     * Remove the specified space from storage.
     */
    public function destroy(Space $space, AuthorizationService $authorizationService): JsonResponse
    {
        $this->authorize('delete', $space);

        // Check if the space is empty before deletion
        if ($space->connections()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete a space that has connections. Please delete all connections first.',
            ], 422);
        }

        $space->delete();
        $authorizationService->invalidateSpace($space);

        return response()->json(null, 204);
    }
}
