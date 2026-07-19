<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Subscription\SubscriptionPeriodService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for the event-driven period reconciliation: closes cycles that
 * rolled over via the hourly LemonSqueezy sync and opens any missing periods.
 * Idempotent — running it repeatedly is a no-op when everything is in sync.
 */
class ReconcileSubscriptionPeriods extends Command
{
    protected $signature = 'subscriptions:reconcile-periods {--dry-run : List candidate spaces without making changes}';

    protected $description = 'Reconcile each space\'s billing-period history with its subscription state';

    public function handle(SubscriptionPeriodService $periods): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be saved');
        }

        $startTime = microtime(true);
        $processed = 0;
        $failed = 0;

        // All spaces, deliberately: a space without any subscription row must
        // also be reconciled so the free-plan safety net can enroll it.
        Space::query()
            ->chunkById(100, function ($spaces) use ($periods, $dryRun, &$processed, &$failed) {
                foreach ($spaces as $space) {
                    if ($dryRun) {
                        $processed++;

                        continue;
                    }

                    try {
                        $periods->reconcile($space);
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("Failed for space {$space->id}: {$e->getMessage()}");
                        Log::error('Subscription period reconcile failed', [
                            'space' => $space->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $duration = round(microtime(true) - $startTime, 2);
        $verb = $dryRun ? 'Would reconcile' : 'Reconciled';
        $this->info("{$verb} {$processed} space(s)".($failed ? ", {$failed} failed" : '').". Done in {$duration}s.");

        return $failed > 0 ? 1 : 0;
    }
}
