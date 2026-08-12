<?php

namespace App\Console\Commands;

use App\Jobs\Space\AssetBackfillJob;
use App\Models\Management\Space;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Shared driver for the asset backfill commands: the space loop (all spaces or
 * the one given by --space), the --sync / --dry-run switches and the error and
 * summary reporting. A subclass supplies its own signature, the job to run and
 * what a dry run reports per space.
 */
abstract class AssetBackfillCommand extends Command
{
    /** @return class-string<AssetBackfillJob> */
    abstract protected function jobClass(): string;

    /** Subject for the failure log, e.g. `asset checksum`. */
    abstract protected function subject(): string;

    /**
     * Report what the job would do for the current space. `currentSpace` is
     * already bound, so space-model queries resolve the right database.
     */
    abstract protected function reportDryRun(Space $space): void;

    /** Optional per-space line after a synchronous run. */
    protected function reportSyncRun(Space $space, AssetBackfillJob $job): void {}

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        if ($dryRun) {
            $this->warn('DRY RUN - no jobs will be dispatched');
        }

        $query = Space::query();

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $spacesQueued = 0;
        $failed = 0;
        $jobClass = $this->jobClass();

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($dryRun, $sync, $jobClass, &$spacesQueued, &$failed) {
            foreach ($spaces as $space) {
                try {
                    if ($dryRun) {
                        $restore = SpaceContext::enter($space);

                        try {
                            $this->reportDryRun($space);
                        } finally {
                            $restore();
                        }

                        continue;
                    }

                    if ($sync) {
                        $job = new $jobClass($space);
                        $job->handle();

                        $this->reportSyncRun($space, $job);
                    } else {
                        $jobClass::dispatch($space);
                    }

                    $spacesQueued++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  {$space->id}: {$e->getMessage()}");
                    Log::error("Failed to queue {$this->subject()} backfill for space", [
                        'space' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete.');
        } else {
            $verb = $sync ? 'Processed' : 'Queued';
            $this->info("{$verb} {$spacesQueued} space(s); {$failed} failed.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
