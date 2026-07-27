<?php

namespace App\Providers;

use App\Models\Space\Comment;
use App\Observers\Space\CommentObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use PostHog\PostHog;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (app()->environment('production')) {
            // Proxy trust is configured in config/trustedproxy.php and applied
            // by the TrustProxies middleware; setting it here has no effect,
            // since that middleware resets it on every request.
            \URL::forceScheme('https');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Comment::observe(CommentObserver::class);

        if (config('services.posthog.api_key')) {
            PostHog::init(
                config('services.posthog.api_key'),
                config('services.posthog.settings', [])
            );
        }
    }
}
