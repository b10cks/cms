<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Space\CreateSpace;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\SpaceFilter;
use App\Http\Requests\Space\CreateSpaceRequest;
use App\Http\Requests\Space\UpdateSpaceRequest;
use App\Http\Resources\Management\SpaceResource;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class SpaceController extends Controller
{
    /**
     * Display a listing of the spaces.
     */
    public function index(Request $request): ResourceCollection
    {
        $this->authorize('viewAny', Space::class);
        $spaces = Space::filter(SpaceFilter::fromRequest($request))
            ->when(! auth()->user()->is_root, function ($query) {
                $query->whereHas('users', function ($query) {
                    $query->where('id', auth()->id());
                })->orWhereIn('team_id', auth()->user()->teams()->wherePivotIn('role', ['member', 'admin', 'owner'])->pluck('id'));
            })
            ->withCount(['users'])
            ->paginate();

        return SpaceResource::collection($spaces);
    }

    /**
     * Store a newly created space in storage.
     */
    public function store(CreateSpaceRequest $request, CreateSpace $action, LemonSqueezyService $ls): SpaceResource
    {
        // $this->authorize('create', Space::class);
        $validated = $request->validated();
        $planId = $validated['plan_id'] ?? null;
        unset($validated['plan_id']);

        $space = $action->execute($validated, auth()->user());

        $checkoutUrl = null;

        if ($planId) {
            $plan = Plan::find($planId);

            if ($plan) {
                if ($plan->is_free || ! $ls->isConfigured() || empty($plan->ls_variant_id)) {
                    // Create free/default subscription immediately
                    Subscription::forceCreate([
                        'space_id' => $space->id,
                        'plan_id' => $plan->id,
                        'name' => $plan->getTranslatedName() ?? 'Free',
                        'status' => 'active',
                        'lemon_squeezy_id' => null,
                        'variant_id' => $plan->ls_variant_id ?? '',
                        'product_id' => $plan->ls_product_id ?? '',
                        'quantity' => 1,
                        'quotas' => $plan->quotas,
                    ]);
                } else {
                    // Create pending subscription first so its ID can be embedded in
                    // checkout customData — the stable link used by webhook and CLI sync.
                    $pending = Subscription::forceCreate([
                        'space_id' => $space->id,
                        'plan_id' => $plan->id,
                        'name' => $plan->getTranslatedName() ?? 'Subscription',
                        'status' => 'pending',
                        'lemon_squeezy_id' => null,
                        'variant_id' => $plan->ls_variant_id,
                        'product_id' => $plan->ls_product_id ?? '',
                        'quantity' => 1,
                        'quotas' => $plan->quotas,
                    ]);

                    try {
                        $user = auth()->user();
                        $redirectUrl = config('app.url')."/{$space->slug}/settings/subscription";

                        $checkout = $ls->createCheckout(
                            variantId: $plan->ls_variant_id,
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
        }

        return (new SpaceResource($space))->additional(['checkout_url' => $checkoutUrl]);
    }

    /**
     * Display the specified space.
     */
    public function show(Space $space): SpaceResource
    {
        $this->authorize('view', $space);
        $space->loadCount(['users']);

        return new SpaceResource($space);
    }

    /**
     * Update the specified space in storage.
     */
    public function update(UpdateSpaceRequest $request, Space $space): SpaceResource
    {
        $this->authorize('update', $space);
        $space->fill($request->validated());

        if (! $space->save()) {
            Log::error('Failed to update space', ['space_id' => $space->id]);
            abort(500, 'Failed to update space');
        }

        $space->loadCount(['users']);

        return new SpaceResource($space);
    }

    /**
     * Remove the specified space from storage.
     */
    public function destroy(Space $space): JsonResponse
    {
        $this->authorize('delete', $space);
        try {
            // Check if the space is empty before deletion
            if ($space->connections()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete a space that has connections. Please delete all connections first.',
                ], 422);
            }

            $space->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete space', [
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the space',
            ], 500);
        }
    }
}
