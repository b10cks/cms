<?php

namespace App\Providers;

use App\Support\EditionGate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('mgmt', function (Request $request) {
            // MCP sub-requests are already throttled via the outer /mcp/v1 request.
            if ($request->attributes->get('mcp-internal')) {
                return Limit::none();
            }

            return Limit::perMinute((int) config('app.mgmt_rate_limit', 1000))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(600)->by($request->bearerToken() ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(60)->by("login|{$request->ip()}"),
                Limit::perMinute(5)->by("login|{$request->input('email')}"),
            ];
        });

        RateLimiter::for('one-time', function (Request $request) {
            return [
                Limit::perMinute(5)->by("one-time|{$request->ip()}"),
                Limit::perMinute(1)->by("one-time|{$request->input('email')}"),
            ];
        });

        RateLimiter::for('shares', function (Request $request) {
            return Limit::perMinute(60)->by("shares|{$request->ip()}");
        });

        // Image previews load many-per-page from <img> tags, so they get a
        // higher ceiling than the JSON share endpoints.
        RateLimiter::for('share-previews', function (Request $request) {
            return Limit::perMinute(300)->by("share-previews|{$request->ip()}");
        });

        RateLimiter::for('share-unlock', function (Request $request) {
            return [
                Limit::perMinute(10)->by("share-unlock|{$request->ip()}"),
                Limit::perMinute(5)->by("share-unlock|{$request->route('token')}"),
            ];
        });

        // Media delivery is unauthenticated and each request can hold a worker
        // for the length of a transfer or a decode, so the ceiling sits well
        // above normal browsing — a content-heavy page pulls many assets at
        // once — while still bounding a flood.
        RateLimiter::for('ilum', function (Request $request) {
            return Limit::perMinute((int) config('ilum.rate_limit', 600))->by("ilum|{$request->ip()}");
        });

        RateLimiter::for('crucial', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::prefix('auth/v1')
                ->middleware('api')
                ->name('auth.')
                ->group(base_path('routes/auth.php'));

            Route::middleware(['mgmt', 'auth:sanctum', 'verified'])
                ->prefix('mgmt/v1')
                ->name('mgmt.')
                ->group(base_path('routes/private_mgmt.php'));

            Route::middleware(['mgmt'])
                ->prefix('mgmt/v1')
                ->name('mgmt.')
                ->group(base_path('routes/public_mgmt.php'));

            Route::middleware(['auth.data', 'api'])
                ->prefix('api/v1')
                ->name('api.')
                ->group(base_path('routes/data_api.php'));

            // Webhook endpoints — no auth, signature verified in middleware.
            // LemonSqueezy callbacks only exist on the billed SaaS deployment.
            if (EditionGate::billingEnabled()) {
                Route::middleware(['api'])
                    ->prefix('webhooks')
                    ->name('webhooks.')
                    ->group(base_path('routes/webhooks.php'));
            }

            // One-shot installer for hosts without shell access; 404s unless
            // explicitly enabled and self-disarms after a successful run.
            // Deliberately outside the 'web' group: session/cookie encryption
            // would require the very APP_KEY this endpoint generates.
            Route::get('/setup', \App\Http\Controllers\Web\SetupController::class)->name('setup');

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::prefix('ilum')
                ->name('ilum.')
                ->group(base_path('routes/ilum.php'));
        });
    }
}
