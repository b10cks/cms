<?php

namespace Tests\Unit\Providers;

use App\Http\Middleware\AuthenticateDataApi;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouteServiceProviderTest extends TestCase
{
    #[Test]
    public function delivery_limits_use_the_authenticated_token_and_client_ip(): void
    {
        $request = Request::create('/api/v1/contents?token=secret', 'GET');
        $request->server->set('REMOTE_ADDR', '192.0.2.10');
        $request->headers->set('Authorization', 'Bearer attacker-controlled');
        $request->attributes->set(AuthenticateDataApi::TOKEN_ID_ATTRIBUTE, 'token-id');
        config(['app.data_api_rate_limit' => 50]);

        $limits = RateLimiter::limiter('api')($request);

        $this->assertSame(['api|token|token-id', 'api|ip|192.0.2.10'], array_column($limits, 'key'));
        $this->assertSame([50, 100], array_column($limits, 'maxAttempts'));
    }

    #[Test]
    public function the_delivery_revision_endpoint_is_not_limited(): void
    {
        $request = Request::create('/api/v1/spaces/me?token=secret', 'GET');
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
        $request->attributes->set(AuthenticateDataApi::TOKEN_ID_ATTRIBUTE, 'token-id');

        $this->assertInstanceOf(Unlimited::class, RateLimiter::limiter('api')($request));
    }

    #[Test]
    public function unauthenticated_api_routes_keep_an_ip_bucket(): void
    {
        $request = Request::create('/webhooks/example', 'POST');
        $request->server->set('REMOTE_ADDR', '192.0.2.11');

        $limit = RateLimiter::limiter('api')($request);

        $this->assertSame('api|ip|192.0.2.11', $limit->key);
    }

    #[Test]
    public function throttling_uses_the_redis_limiter_only_when_the_cache_is_redis(): void
    {
        config(['cache.default' => 'redis']);
        $this->assertInstanceOf(ThrottleRequestsWithRedis::class, app(ThrottleRequests::class));

        config(['cache.default' => 'array']);
        $this->assertNotInstanceOf(ThrottleRequestsWithRedis::class, app(ThrottleRequests::class));
    }
}
