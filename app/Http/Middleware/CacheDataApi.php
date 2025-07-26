<?php

namespace App\Http\Middleware;

class CacheDataApi
{
    public function handle($request, \Closure $next)
    {
        return $next($request)
            ->header('Cache-Control', 'public, max-age=3600, s-maxage=604800')
            ->header('X-b10cks-version', config('app.version'));
    }
}
