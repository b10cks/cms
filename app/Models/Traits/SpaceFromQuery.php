<?php

namespace App\Models\Traits;

use App\Models\Management\Space;
use App\Services\Auth\AuthorizationService;

trait SpaceFromQuery
{
    public function getSpaceFromQuery()
    {
        $space = Space::findOrFail(request()->query('spaceId'));
        abort_unless(\Gate::allows('view', $space), 404);

        return $space;
    }

    /**
     * Authorize a space-scoped ability for the current user, aborting with 403
     * when it is not granted. Use this for actions that go beyond viewing —
     * e.g. AI generation, which both costs money and mutates content and so
     * must not be available to read-only roles.
     */
    protected function authorizeSpaceAbility(Space $space, string $ability): void
    {
        $user = request()->user();

        abort_unless(
            $user !== null && app(AuthorizationService::class)->canInSpace($user, $space, $ability),
            403,
        );
    }
}
