<?php

namespace App\Providers;

use App\Models\Space\Comment;
use App\Observers\Space\CommentObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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

        $this->warnAboutUntrustedProxies();

        Comment::observe(CommentObserver::class);

        if (config('services.posthog.api_key')) {
            PostHog::init(
                config('services.posthog.api_key'),
                config('services.posthog.settings', [])
            );
        }
    }

    /**
     * An unset TRUSTED_PROXIES is a silent misconfiguration, not a safe one.
     *
     * Behind a load balancer it makes every request appear to come from the
     * balancer, so the per-IP limiters on login, one-time tokens and share
     * unlock all collapse into a single bucket one client can exhaust for
     * everybody, and the audit log records the balancer's address for every
     * action. Nothing breaks visibly, which is why this says so out loud.
     */
    private function warnAboutUntrustedProxies(): void
    {
        if (! $this->app->isProduction() || $this->app->runningUnitTests()) {
            return;
        }

        if (! empty(config('trustedproxy.proxies'))) {
            return;
        }

        Log::error(
            'TRUSTED_PROXIES is not set. If this instance sits behind a load balancer, '
            .'every request will be attributed to the balancer: per-IP rate limits share '
            .'one bucket and audit logs record the wrong address. Set it to the balancer\'s '
            .'CIDR range, or to "*" if the application is only reachable through it.'
        );
    }
}
