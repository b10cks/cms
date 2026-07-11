<?php

namespace App\Http\Middleware;

class CacheDataApi
{
    public const string TTL_ATTRIBUTE = 'api.cache.ttl';

    public const string TAGS_ATTRIBUTE = 'api.cache.tags';

    public function handle($request, \Closure $next, $maxAge = 3600, $serverMaxAge = 86400)
    {
        $response = $next($request);

        $ttl = $request->attributes->get(self::TTL_ATTRIBUTE);
        if (\is_int($ttl)) {
            $maxAge = min((int) $maxAge, $ttl);
            $serverMaxAge = $ttl;
        }

        $response->header('cache-control', "public, max-age=$maxAge, s-maxage=$serverMaxAge");

        $tags = $request->attributes->get(self::TAGS_ATTRIBUTE);
        if (\is_array($tags) && $tags !== []) {
            $response->header('cache-tag', implode(',', $tags));
        }

        return $response;
    }
}
