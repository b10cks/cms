<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short-lived origin cache for the heavy delivery endpoints. Because the
 * space revision (rv) and token are part of the URL, a publish naturally
 * rolls the cache key — no invalidation logic is needed; entries expire on
 * their own. Its job is to collapse the CDN-miss stampede after a publish:
 * each unique URL is computed once per TTL window instead of once per request.
 */
class MicroCacheDataApi
{
    /** Responses larger than this are not worth holding in the cache store. */
    private const int MAX_CACHEABLE_BYTES = 1024 * 1024;

    public function handle(Request $request, Closure $next, ?int $ttl = null): Response
    {
        $ttl ??= (int) config('app.micro_cache_ttl');

        if ($ttl <= 0 || ! $request->isMethodCacheable()) {
            return $next($request);
        }

        // The full URL includes the token and rv query params, so entries are
        // isolated per space/token and busted per revision.
        $key = 'data-api:micro:' . sha1($request->fullUrl());

        $cached = Cache::get($key);
        if (\is_array($cached)) {
            $request->attributes->set(CacheDataApi::TTL_ATTRIBUTE, $cached['ttl']);
            $request->attributes->set(CacheDataApi::TAGS_ATTRIBUTE, $cached['tags']);

            return response($cached['content'], 200, [
                'content-type' => $cached['content_type'],
                'x-b10cks-cache' => 'hit',
            ]);
        }

        $response = $next($request);

        $content = $response->getContent();
        if ($response->getStatusCode() === 200 && \is_string($content) && \strlen($content) <= self::MAX_CACHEABLE_BYTES) {
            Cache::put($key, [
                'content' => $content,
                'content_type' => $response->headers->get('content-type'),
                'ttl' => $request->attributes->get(CacheDataApi::TTL_ATTRIBUTE),
                'tags' => $request->attributes->get(CacheDataApi::TAGS_ATTRIBUTE),
            ], $ttl);
        }

        $response->headers->set('x-b10cks-cache', 'miss');

        return $response;
    }
}
