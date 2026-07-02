<?php

namespace App\Http\Middleware;

use App\Models\Management\Space;
use App\Services\Auth\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth tenant isolation gate for the `spaces/{space}` route group.
 *
 * Per-controller `authorize()` / `canInSpace()` calls remain the primary
 * authorization layer, but a single forgotten check must never become a
 * cross-tenant hole. This middleware fails closed: unless the authenticated
 * user has at least read access to the space bound on the route, the request
 * is rejected before it reaches the controller.
 */
class EnsureSpaceMembership
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $space = $request->route('space');

        if (! $space instanceof Space) {
            abort(404);
        }

        $user = $request->user();

        abort_if($user === null, 401);
        abort_unless($this->authorization->canInSpace($user, $space, 'space.view'), 403);

        return $next($request);
    }
}
