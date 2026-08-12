<?php

namespace App\Console\Commands;

use App\Jobs\Space\BackfillAssetChecksumsJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;

/**
 * Dispatches a BackfillAssetChecksumsJob for every space (or a single one)
 * to compute the sha256 `checksum` for assets that were uploaded before
 * checksum computation existed.
 */
class BackfillAssetChecksumsCommand extends AssetBackfillCommand
{
    protected $signature = 'assets:backfill-checksums
        {--space= : Limit to a single space (id or slug)}
        {--sync : Run the backfill synchronously instead of dispatching a queued job}
        {--dry-run : Report how many assets are missing a checksum without doing any work}';

    protected $description = 'Backfill the checksum column for pre-existing assets across space databases';

    protected function jobClass(): string
    {
        return BackfillAssetChecksumsJob::class;
    }

    protected function subject(): string
    {
        return 'asset checksum';
    }

    protected function reportDryRun(Space $space): void
    {
        $missing = Asset::query()->whereNull('checksum')->count();

        if ($missing > 0) {
            $this->line("  {$space->id}  {$missing} asset(s) missing checksum");
        }
    }
}
