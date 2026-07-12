<?php

namespace App\Http\Middleware;

use Closure;

class VersionHeader
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('x-b10cks-version', config('app.version'));

        return $response;
    }
}
