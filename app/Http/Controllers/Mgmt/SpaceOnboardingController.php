<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Requests\Space\UpdateSpaceOnboardingRequest;
use App\Http\Resources\Management\SpaceResource;
use App\Models\Management\Space;

/**
 * Dismisses (or restores) the onboarding guide for a space.
 *
 * Its own endpoint rather than a field on SpaceController::update, which expects
 * a full space payload and replaces `settings` wholesale — far too much for a
 * sidebar toggle. This merges into the existing settings, so only a concurrent
 * save from another tab can drop the flag.
 */
class SpaceOnboardingController extends Controller
{
    /**
     * Dismiss or restore the onboarding guide.
     *
     * Onboarding state is shared across the space: dismissing it hides the guide
     * for every member, so it is gated on the same ability as other space edits.
     */
    public function update(UpdateSpaceOnboardingRequest $request, Space $space)
    {
        $this->authorize('update', $space);

        $dismissed = $request->validated('dismissed');

        // Merge into the stored settings, not `$space->settings->toArray()`, which
        // folds in every default and would freeze today's values into the column.
        $stored = json_decode($space->getRawOriginal('settings') ?? '', true);

        $space->settings = [
            ...(\is_array($stored) ? $stored : []),
            'onboarding_dismissed_at' => $dismissed ? now()->toIso8601String() : null,
        ];
        $space->save();

        return new SpaceResource($space);
    }
}
