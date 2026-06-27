<?php

namespace App\Models\Traits;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Services\Auth\AuthorizationService;

trait SpaceFromQuery
{
    public function getSpaceFromQuery(): Space
    {
        $space = Space::findOrFail(request()->query('spaceId'));
        abort_unless(\Gate::allows('view', $space), 404);

        return $space;
    }

    /**
     * Resolve the AI config to use for a request: the one identified by
     * `$configId` when given and it exists, otherwise the space default. An
     * unknown id falls back to the default rather than erroring, so a stale
     * client selection degrades gracefully.
     */
    protected function resolveAiConfig(Space $space, ?string $configId): ?SpaceAiConfig
    {
        if ($configId) {
            return $space->aiConfigs()->find($configId) ?? $space->defaultAiConfig;
        }

        return $space->defaultAiConfig;
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
