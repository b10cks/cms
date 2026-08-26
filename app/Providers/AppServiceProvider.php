<?php

namespace App\Providers;

use App\Models\Space\Comment;
use App\Observers\Space\CommentObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use PostHog\PostHog;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * APP_URL, not APP_ENV, decides the scheme generated URLs get. The
     * self-hosted Docker stack runs APP_ENV=production over plain HTTP on
     * localhost, so keying this on the environment pointed every asset URL at
     * an https port nothing listens on. Behind a TLS terminating proxy the
     * operator sets an https APP_URL and the forcing still applies, which also
     * covers an unset TRUSTED_PROXIES, where X-Forwarded-Proto is not believed.
     *
     * Proxy trust itself is configured in config/trustedproxy.php and applied
     * by the TrustProxies middleware; setting it here has no effect, since
     * that middleware resets it on every request.
     */
    public function register(): void
    {
        if (str_starts_with(strtolower((string) config('app.url')), 'https://')) {
            \URL::forceScheme('https');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Laravel localizes translation strings but not Carbon, so relative
        // dates in a translated mail ("expires in 7 days") would stay English.
        Event::listen(LocaleUpdated::class, fn (LocaleUpdated $event) => Carbon::setLocale($event->locale));

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
