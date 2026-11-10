<?php

namespace App\Http\Middleware;

class CacheDataApi
{
    public const string TTL_ATTRIBUTE = 'api.cache.ttl';

    public const string TAGS_ATTRIBUTE = 'api.cache.tags';

    /** An upper bound in seconds that holds whatever TTL the controller sets. */
    public const string MAX_TTL_ATTRIBUTE = 'api.cache.max_ttl';

    public function handle($request, \Closure $next, $maxAge = 3600, $serverMaxAge = 86400, $staleWhileRevalidate = 60)
    {
        $response = $next($request);

        $ttl = $request->attributes->get(self::TTL_ATTRIBUTE);
        if (\is_int($ttl)) {
            $maxAge = min((int) $maxAge, $ttl);
            $serverMaxAge = $ttl;
        }

        $maxTtl = $request->attributes->get(self::MAX_TTL_ATTRIBUTE);
        if (\is_int($maxTtl)) {
            $maxAge = min((int) $maxAge, $maxTtl);
            $serverMaxAge = min((int) $serverMaxAge, $maxTtl);
        }

        $response->header(
            'cache-control',
            "public, max-age=$maxAge, s-maxage=$serverMaxAge, stale-while-revalidate=$staleWhileRevalidate, stale-if-error=86400"
        );

        $tags = $request->attributes->get(self::TAGS_ATTRIBUTE);
        if (\is_array($tags) && $tags !== []) {
            $response->header('cache-tag', implode(',', $tags));
        }

        return $response;
    }
}
