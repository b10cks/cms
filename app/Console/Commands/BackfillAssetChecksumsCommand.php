<?php

namespace App\Console\Commands;

use App\Jobs\Space\BackfillAssetChecksumsJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a BackfillAssetChecksumsJob for every space (or a single one)
 * to compute the sha256 `checksum` for assets that were uploaded before
 * checksum computation existed.
 */
class BackfillAssetChecksumsCommand extends Command
{
    protected $signature = 'assets:backfill-checksums
        {--space= : Limit to a single space (id or slug)}
        {--sync : Run the backfill synchronously instead of dispatching a queued job}
        {--dry-run : Report how many assets are missing a checksum without doing any work}';

    protected $description = 'Backfill the checksum column for pre-existing assets across space databases';

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

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($dryRun, $sync, &$spacesQueued, &$failed) {
            foreach ($spaces as $space) {
                try {
                    if ($dryRun) {
                        app()->offsetSet('currentSpace', $space);
                        $missing = Asset::query()->whereNull('checksum')->count();

                        if ($missing > 0) {
                            $this->line("  {$space->id}  {$missing} asset(s) missing checksum");
                        }

                        continue;
                    }

                    if ($sync) {
                        (new BackfillAssetChecksumsJob($space))->handle();
                    } else {
                        BackfillAssetChecksumsJob::dispatchSync($space);
                    }

                    $spacesQueued++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to queue asset checksum backfill for space', [
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
            $this->info("Queued {$spacesQueued} space(s); {$failed} failed.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
