<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credentialed CORS for the cookie-authenticated management API
 * (`mgmt/*`, `auth/v1/*`). These routes are deliberately excluded from the
 * global `*`/no-credentials CORS config and handled here instead, so that
 * cross-origin credentialed access is only granted to the b10cks app's own
 * known origins (never `*`, which with credentials would allow session riding).
 *
 * Runs in the global middleware stack so preflight OPTIONS is answered before
 * routing/auth, mirroring Laravel's own HandleCors.
 */
class HandleManagementCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->appliesTo($request)) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        $originAllowed = $origin !== null && $this->isAllowedOrigin($origin);

        // Answer preflight without dispatching to the route.
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($originAllowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');

            $vary = $response->headers->get('Vary');
            $response->headers->set('Vary', $vary ? $vary . ', Origin' : 'Origin');

            if ($request->isMethod('OPTIONS')) {
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set(
                    'Access-Control-Allow-Headers',
                    $request->headers->get('Access-Control-Request-Headers', '*')
                );
                $response->headers->set('Access-Control-Max-Age', '3600');
            }
        }

        return $response;
    }

    private function appliesTo(Request $request): bool
    {
        return $request->is(...config('cors.management_paths', ['mgmt/*', 'auth/v1/*']));
    }

    private function isAllowedOrigin(string $origin): bool
    {
        return in_array($origin, config('cors.management_origins', []), true);
    }
}
