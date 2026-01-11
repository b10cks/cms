<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
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
            return Limit::perSecond(10)->by($request->user()?->id ?: $request->ip());
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

        RateLimiter::for('crucial', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::prefix('auth/v1')
                ->middleware('api')
                ->name('auth.')
                ->group(base_path('routes/auth.php'));

            Route::middleware(['mgmt', 'auth:api'])
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

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::prefix('ilum')
                ->name('ilum.')
                ->group(base_path('routes/ilum.php'));
        });
    }
}
