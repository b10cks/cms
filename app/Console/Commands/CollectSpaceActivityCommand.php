<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Provider\SpaceActivityCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rolls per-space content and AI activity up into the management DB for the
 * provider dashboard. Runs hourly over the last two days; spaces without any
 * rollup yet, and `--full`, rebuild their whole history.
 */
class CollectSpaceActivityCommand extends Command
{
    protected $signature = 'provider:collect-activity
        {--full : Rebuild every space\'s history instead of the last two days}';

    protected $description = 'Roll per-space edits, publishes and AI runs up into daily rows for the provider dashboard';

    public function handle(SpaceActivityCollector $collector): int
    {
        $since = $this->option('full') ? null : now()->toImmutable()->subDay()->startOfDay();
        $failed = 0;

        Space::query()->with('defaultConnection')->orderBy('id')->chunkById(100, function ($spaces) use ($collector, $since, &$failed) {
            foreach ($spaces as $space) {
                try {
                    $collector->collect($space, $since);
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Failed to collect space activity', ['space' => $space->id, 'error' => $e->getMessage()]);
                }
            }
        });

        $this->info($failed > 0 ? "{$failed} space(s) failed." : 'Space activity collected.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
