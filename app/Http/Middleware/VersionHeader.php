<?php

namespace App\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VersionHeader
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof StreamedResponse) {
            $response->headers->set('x-b10cks-version', config('app.version'));
        } else {
            $response->header('x-b10cks-version', config('app.version'));
        }

        return $response;
    }
}
