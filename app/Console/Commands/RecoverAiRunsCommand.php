<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Ai\Run\RunRecovery;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds AI operations and runs that lost their worker, space by space, and
 * continues them. Safe to run at any time and any number of times.
 */
class RecoverAiRunsCommand extends Command
{
    protected $signature = 'ai:recover-runs';

    protected $description = 'Continue AI operations and background runs that lost their worker';

    public function handle(RunRecovery $recovery): int
    {
        $operations = 0;
        $runs = 0;
        $failed = 0;

        Space::query()->with('defaultConnection')->orderBy('id')->chunkById(100, function ($spaces) use ($recovery, &$operations, &$runs, &$failed) {
            foreach ($spaces as $space) {
                $restore = SpaceContext::enter($space);

                try {
                    $swept = $recovery->sweep();
                    $operations += $swept['operations'];
                    $runs += $swept['runs'];
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Failed to recover AI runs of a space', ['space' => $space->id, 'error' => $e->getMessage()]);
                } finally {
                    $restore();
                }
            }
        });

        $this->info("{$operations} operation(s) advanced, {$runs} run(s) recovered; {$failed} space(s) failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
