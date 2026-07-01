<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetVersion;
use App\Services\Storage\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Prunes old asset version snapshots (files + rows), per asset, keeping at
 * most `--keep` recent versions that are no older than `--days`. Both bounds
 * apply simultaneously (a version must satisfy the tighter of the two to be
 * kept), i.e. retention = min(keep-count window, max-age window).
 */
class PruneAssetVersionsCommand extends Command
{
    protected $signature = 'assets:prune-versions
        {--space= : Limit to a single space (id or slug)}
        {--keep=10 : Maximum number of most-recent versions to keep per asset}
        {--days=90 : Maximum age in days for a kept version}
        {--dry-run : Report how many versions would be pruned without deleting anything}';

    protected $description = 'Prune old asset version snapshots, keeping at most N recent versions no older than X days (whichever is more restrictive)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keep = max(0, (int) $this->option('keep'));
        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        if ($dryRun) {
            $this->warn('DRY RUN - no versions will be deleted');
        }

        $query = Space::query();

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $spacesProcessed = 0;
        $totalDeleted = 0;
        $totalFailed = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($dryRun, $keep, $cutoff, &$spacesProcessed, &$totalDeleted, &$totalFailed) {
            foreach ($spaces as $space) {
                try {
                    $deleted = $this->pruneSpace($space, $keep, $cutoff, $dryRun);
                    $totalDeleted += $deleted;
                    $spacesProcessed++;

                    if ($deleted > 0) {
                        $this->line(sprintf(
                            '  %s  %s %d version(s)',
                            str_pad($space->id, 28),
                            $dryRun ? 'would delete' : 'deleted',
                            $deleted
                        ));
                    }
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $this->error("  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to prune asset versions for space', [
                        'space' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->newLine();
        $verb = $dryRun ? 'would be deleted' : 'deleted';
        $this->info("Processed {$spacesProcessed} space(s); {$totalDeleted} version(s) {$verb}; {$totalFailed} space(s) failed.");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pruneSpace(Space $space, int $keep, Carbon $cutoff, bool $dryRun): int
    {
        app()->offsetSet('currentSpace', $space);

        $deleted = 0;
        $filesystem = null;

        Asset::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($assets) use ($keep, $cutoff, $dryRun, $space, &$deleted, &$filesystem) {
                foreach ($assets as $asset) {
                    $versions = AssetVersion::query()
                        ->where('asset_id', $asset->id)
                        ->orderByDesc('version_number')
                        ->get()
                        ->values();

                    foreach ($versions as $index => $version) {
                        $rank = $index + 1;
                        $isTooOld = $version->created_at !== null && $version->created_at->lt($cutoff);
                        $isBeyondKeepCount = $rank > $keep;

                        if (! $isBeyondKeepCount && ! $isTooOld) {
                            continue;
                        }

                        $deleted++;

                        if ($dryRun) {
                            continue;
                        }

                        if ($version->path) {
                            try {
                                $filesystem ??= app(StorageService::class)->getDefaultStorage($space);

                                if ($filesystem->fileExists($version->path)) {
                                    $filesystem->delete($version->path);
                                }
                            } catch (\Throwable $e) {
                                Log::warning('Failed to delete pruned asset version file', [
                                    'space_id' => $space->id,
                                    'asset_version_id' => $version->id,
                                    'path' => $version->path,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        $version->delete();
                    }
                }
            });

        return $deleted;
    }
}
