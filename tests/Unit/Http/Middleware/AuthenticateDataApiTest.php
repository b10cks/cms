<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\AuthenticateDataApi;
use App\Models\Management\Space;
use App\Models\Management\Token;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tests\TestCase;

#[CoversClass(AuthenticateDataApi::class)]
class AuthenticateDataApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private AuthenticateDataApi $middleware;
    private Request $request;
    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new AuthenticateDataApi();
        $this->request = new Request();

        $router = app('router');
        $router->get('/{space}/', function () {
            return 'ok';
        })->middleware(AuthenticateDataApi::class);

        $router->model('space', Space::class, function ($value) {
            return Space::where('slug', $value)->firstOrFail();
        });

        $this->space = Space::factory()->create(['slug' => 'test-space', 'state' => 'live']);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'valid-token',
        ]);
    }

    protected function mockRoute(array $params, string $spaceSlug = 'test-space'): void
    {
        $headers = $this->request->headers->all();

        $this->request = Request::create(
            "/{$spaceSlug}",
            'GET',
            $params
        );

        foreach ($headers as $key => $values) {
            $this->request->headers->set($key, $values);
        }

        $this->app->instance('request', $this->request);

        $router = app('router');
        $route = $router->getRoutes()->match($this->request);

        $this->request->setRouteResolver(function () use ($route) {
            return $route;
        });
    }

    #[Test]
    public function it_authenticates_valid_requests(): void
    {
        $this->mockRoute(['token' => 'valid-token']);

        $response = $this->middleware->handle($this->request, function () {
            return 'next';
        });

        $this->assertEquals('next', $response);
    }

    #[Test]
    public function it_fails_without_token(): void
    {
        $this->mockRoute([]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No API token provided');

        $this->middleware->handle($this->request, fn () => null);
    }

    #[Test]
    public function it_fails_with_invalid_token(): void
    {
        $this->mockRoute(['token' => 'invalid-token']);

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid or expired api token');

        $this->middleware->handle($this->request, fn () => null);
    }

    #[Test]
    public function it_fails_with_expired_token(): void
    {
        Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'expired-token',
            'expires_at' => now()->subDay()
        ]);

        $this->mockRoute(['token' => 'expired-token']);

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid or expired api token');

        $this->middleware->handle($this->request, fn () => null);
    }

    #[Test]
    public function it_accepts_tokens_with_null_expiration(): void
    {
        Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'perpetual-token',
            'expires_at' => null
        ]);

        $this->mockRoute(['token' => 'perpetual-token']);

        $response = $this->middleware->handle($this->request, function () {
            return 'next';
        });

        $this->assertEquals('next', $response);
    }

    #[Test]
    public function it_validates_token_belongs_to_correct_space(): void
    {
        $otherSpace = Space::factory()->create();
        $otherToken = Token::factory()->create([
            'space_id' => $otherSpace->id,
            'token' => 'other-base-token',
        ]);

        $this->mockRoute(['token' => 'other-space-token']);

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid or expired api token');

        $this->middleware->handle($this->request, fn () => null);
    }
}
