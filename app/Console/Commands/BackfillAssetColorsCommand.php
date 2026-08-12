<?php

namespace App\Console\Commands;

use App\Jobs\Space\BackfillAssetColorsJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Support\SpaceContext;
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
                        $restore = SpaceContext::enter($space);

                        try {
                            $pending = Asset::query()
                                ->where(fn ($q) => $q
                                    ->where('mime_type', 'like', 'image/%')
                                    ->orWhere('mime_type', 'like', 'video/%'))
                                ->get()
                                ->filter(fn (Asset $asset) => BackfillAssetColorsJob::needsWork($asset->metadata ?? [], $asset->mime_type));

                            foreach ($pending as $asset) {
                                $this->line("  {$space->id}  {$asset->id}  {$asset->filename}.{$asset->extension} ({$asset->mime_type}): ".implode(', ', $this->pendingReasons($asset)));
                            }
                        } finally {
                            $restore();
                        }

                        continue;
                    }

                    if ($sync) {
                        $job = new BackfillAssetColorsJob($space);
                        $job->handle();

                        $stats = $job->stats;
                        $this->line(
                            "  {$space->id}  ".($stats
                                ? "{$stats['updated']} updated, {$stats['skipped']} skipped, {$stats['failed']} failed of {$stats['total']} image/video asset(s)"
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

    /**
     * @return list<string>
     */
    private function pendingReasons(Asset $asset): array
    {
        $metadata = $asset->metadata ?? [];
        $reasons = [];

        if (isset($metadata['extraction_error'])) {
            $reasons[] = "failed extraction ({$metadata['extraction_error']})";
        }

        if (! isset($metadata['dominant_color'])) {
            $reasons[] = 'missing dominant color';
        } elseif (! isset($metadata['a11y'])) {
            $reasons[] = 'missing a11y stats';
        }

        return $reasons;
    }
}
