<?php

namespace App\Http\Middleware;

class CacheDataApi
{
    public function handle($request, \Closure $next, $maxAge = 3600, $serverMaxAge = 86400)
    {
        return $next($request)
            ->header('cache-control', "public, max-age=$maxAge, s-maxage=$serverMaxAge");
    }
}
