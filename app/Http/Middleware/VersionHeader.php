<?php

namespace App\Http\Middleware;

use Closure;

class VersionHeader
{
    public function handle($request, Closure $next)
    {
        return $next($request)
            ->header('x-b10cks-version', config('app.version'));
    }
}
