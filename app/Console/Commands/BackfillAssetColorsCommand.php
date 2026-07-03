<?php

namespace App\Console\Commands;

use App\Jobs\Space\BackfillAssetColorsJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a BackfillAssetColorsJob for every space (or a single one) to
 * extract `metadata.dominant_color` for image/video assets uploaded before
 * dominant-color extraction existed.
 */
class BackfillAssetColorsCommand extends Command
{
    protected $signature = 'assets:backfill-colors
        {--space= : Limit to a single space (id or slug)}
        {--sync : Run the backfill synchronously instead of dispatching a queued job}
        {--dry-run : Report how many assets are missing a dominant color without doing any work}';

    protected $description = 'Backfill metadata.dominant_color for pre-existing image/video assets across space databases';

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
                        $missing = Asset::query()
                            ->where(fn ($q) => $q
                                ->where('mime_type', 'like', 'image/%')
                                ->orWhere('mime_type', 'like', 'video/%'))
                            ->get()
                            ->filter(fn (Asset $asset) => ! isset($asset->metadata['dominant_color'])
                                || ! isset($asset->metadata['a11y'])
                                || isset($asset->metadata['extraction_error']))
                            ->count();

                        if ($missing > 0) {
                            $this->line("  {$space->id}  {$missing} asset(s) missing dominant color/a11y stats or with failed extraction");
                        }

                        continue;
                    }

                    if ($sync) {
                        $job = new BackfillAssetColorsJob($space);
                        $job->handle();

                        $stats = $job->stats;
                        $this->line(
                            "  {$space->id}  ".($stats
                                ? "{$stats['updated']} updated, {$stats['failed']} failed of {$stats['total']} image/video asset(s)"
                                : 'done')
                        );
                    } else {
                        BackfillAssetColorsJob::dispatch($space);
                    }

                    $spacesQueued++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to queue asset color backfill for space', [
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
