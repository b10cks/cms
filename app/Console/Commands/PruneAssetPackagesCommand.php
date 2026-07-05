<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Models\Space\AssetPackage;
use App\Models\Space\AssetShare;
use App\Models\Space\AssetShareEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Prunes expired asset download packages (transfers-disk archive + row) and
 * aged-out share access events. Packages, shares and events live in each
 * space's own database, so the command iterates all spaces.
 *
 * Shares that still reference a pruned package are detached
 * (package_id = null); their next public download transparently triggers a
 * rebuild.
 */
class PruneAssetPackagesCommand extends Command
{
    protected $signature = 'assets:prune-packages
        {--dry-run : Report how many packages would be pruned without deleting anything}';

    protected $description = 'Delete expired asset download packages and aged-out share events across all spaces';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no packages will be deleted');
        }

        $pruned = 0;
        $failed = 0;
        $prunedEvents = 0;

        $hadSpace = app()->bound('currentSpace');
        $priorSpace = $hadSpace ? app('currentSpace') : null;

        try {
            foreach (Space::query()->cursor() as $space) {
                app()->offsetSet('currentSpace', $space);

                try {
                    $pruned += $this->prunePackages($dryRun, $failed);
                    $prunedEvents += $this->pruneShareEvents($dryRun);
                } catch (\Throwable $e) {
                    // A space whose database is unreachable (or not yet
                    // migrated) must not stop the sweep for everyone else.
                    $failed++;
                    Log::warning('Skipping space while pruning asset packages', [
                        'space_id' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            if ($hadSpace) {
                app()->offsetSet('currentSpace', $priorSpace);
            } else {
                app()->offsetUnset('currentSpace');
            }
        }

        $verb = $dryRun ? 'would be pruned' : 'pruned';
        $this->info("{$pruned} package(s) {$verb}; {$failed} failed.");
        $this->info("{$prunedEvents} share event(s) {$verb}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function prunePackages(bool $dryRun, int &$failed): int
    {
        $pruned = 0;

        AssetPackage::expired()
            ->orderBy('id')
            ->chunkById(100, function ($packages) use ($dryRun, &$pruned, &$failed) {
                foreach ($packages as $package) {
                    if ($dryRun) {
                        $pruned++;
                        $this->line("  would prune {$package->id} ({$package->s3_path})");

                        continue;
                    }

                    try {
                        if ($package->s3_path) {
                            $disk = Storage::disk('transfers');

                            if ($disk->exists($package->s3_path)) {
                                $disk->delete($package->s3_path);
                            }
                        }

                        AssetShare::withTrashed()
                            ->where('package_id', $package->id)
                            ->update(['package_id' => null]);

                        $package->delete();
                        $pruned++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("  {$package->id}: {$e->getMessage()}");
                        Log::error('Failed to prune asset package', [
                            'package_id' => $package->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $pruned;
    }

    /**
     * Share access events are analytics with a bounded retention window —
     * without pruning, a popular long-lived share grows the table unboundedly.
     */
    private function pruneShareEvents(bool $dryRun): int
    {
        $retentionDays = (int) config('asset_distribution.share_event_retention_days', 365);

        if ($retentionDays <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($retentionDays);

        if ($dryRun) {
            return AssetShareEvent::query()->where('created_at', '<', $cutoff)->count();
        }

        $deleted = 0;

        do {
            $batch = AssetShareEvent::query()
                ->where('created_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $deleted += $batch;
        } while ($batch === 5000);

        return $deleted;
    }
}
