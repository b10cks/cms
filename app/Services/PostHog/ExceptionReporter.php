<?php

namespace App\Services\PostHog;

use PostHog\PostHog;
use Throwable;

/**
 * Reports unexpected exceptions to PostHog error tracking.
 *
 * Reporting is best-effort: it is skipped when no PostHog key is configured
 * (the client is only initialized then, see AppServiceProvider) or while
 * running tests, and it must never throw itself. The posthog-php client only
 * queues the event here; the batch is flushed on shutdown, so the request is
 * not blocked.
 */
class ExceptionReporter
{
    public function report(Throwable $e): void
    {
        if (! config('services.posthog.api_key') || app()->runningUnitTests()) {
            return;
        }

        try {
            PostHog::captureException($e, null, [
                'environment' => app()->environment(),
                'app_version' => config('app.version'),
            ]);
        } catch (Throwable) {
            // Error reporting must never take the request down with it.
        }
    }
}
