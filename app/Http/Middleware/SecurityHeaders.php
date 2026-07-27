<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers for the whole application.
 *
 * The admin console is a session-authenticated SPA, so the important one is
 * `frame-ancestors 'none'`: without it any site can frame the console and
 * clickjack a logged-in owner into a destructive action. The rest limits the
 * blast radius of an HTML-injection bug elsewhere in the app.
 *
 * Responses that need their own policy — the field-plugin sandbox, image
 * delivery, icon delivery — set `content-security-policy` themselves and are
 * left alone here.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('x-content-type-options', 'nosniff', false);
        $headers->set('referrer-policy', 'strict-origin-when-cross-origin', false);

        // A response that ships its own policy has already decided who may
        // frame it — the plugin sandbox is framed by the console on purpose,
        // and X-Frame-Options would override its frame-ancestors in browsers
        // that still honour the older header.
        if (! $headers->has('content-security-policy')) {
            $headers->set('content-security-policy', "frame-ancestors 'none'");
            $headers->set('x-frame-options', 'DENY', false);
        }

        if ($request->isSecure()) {
            $headers->set('strict-transport-security', 'max-age=31536000; includeSubDomains', false);
        }

        return $response;
    }
}
