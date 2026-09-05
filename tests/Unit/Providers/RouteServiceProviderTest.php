<?php

namespace Tests\Unit\Providers;

use App\Http\Middleware\AuthenticateDataApi;
use Illuminate\Http\Request;
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

        $limits = RateLimiter::limiter('api')($request);

        $this->assertSame(['api|token|token-id', 'api|ip|192.0.2.10'], array_column($limits, 'key'));
    }

    #[Test]
    public function unauthenticated_api_routes_keep_an_ip_bucket(): void
    {
        $request = Request::create('/webhooks/example', 'POST');
        $request->server->set('REMOTE_ADDR', '192.0.2.11');

        $limit = RateLimiter::limiter('api')($request);

        $this->assertSame('api|ip|192.0.2.11', $limit->key);
    }
}
