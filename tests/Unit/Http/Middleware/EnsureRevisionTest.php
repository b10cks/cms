<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\CacheDataApi;
use App\Http\Middleware\EnsureRevision;
use App\Models\Management\Space;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The revision redirect used to pin its scheme to APP_ENV, so a plain HTTP
 * self-host sent every delivery request to an https port nothing serves.
 */
#[CoversClass(EnsureRevision::class)]
class EnsureRevisionTest extends TestCase
{
    private EnsureRevision $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new EnsureRevision;

        app()->instance('currentSpace', (new Space)->forceFill([
            'content_updated_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function redirectLocation(string $env, string $appUrl): string
    {
        $this->app['env'] = $env;
        config(['app.url' => $appUrl]);

        URL::forceScheme(null);
        URL::setRequest(Request::create('http://localhost:8000/'));
        $this->app->register(AppServiceProvider::class, force: true);

        $response = $this->middleware->handle(
            Request::create('http://localhost:8000/contents/home'),
            fn () => response('unreachable'),
        );

        $this->assertSame(301, $response->getStatusCode());

        return $response->getTargetUrl();
    }

    #[Test]
    public function it_redirects_over_http_when_app_url_is_http(): void
    {
        $this->assertStringStartsWith('http://', $this->redirectLocation('production', 'http://localhost:8000'));
    }

    #[Test]
    public function it_redirects_over_https_when_app_url_is_https(): void
    {
        $this->assertStringStartsWith('https://', $this->redirectLocation('local', 'https://cms.example.com'));
    }

    #[Test]
    public function a_revision_from_the_future_is_served_but_only_cached_briefly(): void
    {
        $current = app('currentSpace')->rv;
        $serve = function (int $rv): string {
            $request = Request::create("http://localhost:8000/contents/home?rv={$rv}");

            return (new CacheDataApi)->handle(
                $request,
                fn () => $this->middleware->handle($request, fn () => response('served')),
            )->headers->get('cache-control');
        };

        $this->assertStringContainsString('s-maxage=10,', $serve($current + 3600));
        $this->assertStringContainsString('s-maxage=86400,', $serve($current));
    }
}
