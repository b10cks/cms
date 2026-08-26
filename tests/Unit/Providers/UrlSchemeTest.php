<?php

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The scheme URLs are generated with follows APP_URL, not APP_ENV. The
 * self-hosted Docker stack runs APP_ENV=production over plain HTTP, and used
 * to get https asset URLs pointing at a port nothing listens on.
 *
 * Both cases arrive over plain HTTP, so the assertions turn on APP_URL alone.
 */
class UrlSchemeTest extends TestCase
{
    private function bootWith(string $env, string $appUrl): void
    {
        $this->app['env'] = $env;
        config(['app.url' => $appUrl]);

        URL::forceScheme(null);
        URL::setRequest(Request::create('http://localhost:8000/'));

        $this->app->register(AppServiceProvider::class, force: true);
    }

    public function test_http_app_url_keeps_http_in_production(): void
    {
        $this->bootWith('production', 'http://localhost:8000');

        $this->assertStringStartsWith('http://', asset('build/app.js'));
        $this->assertStringStartsWith('http://', url('/login'));
    }

    public function test_https_app_url_forces_https_behind_a_terminating_proxy(): void
    {
        $this->bootWith('local', 'https://cms.example.com');

        $this->assertStringStartsWith('https://', asset('build/app.js'));
        $this->assertStringStartsWith('https://', url('/login'));
    }
}
