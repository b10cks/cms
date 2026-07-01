<?php

namespace App\Console\Commands;

use App\Enums\AssetRightsStatus;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the `rights_status` of every asset from its `license_expires_at`
 * timestamp: expired if the license date has passed, restricted if a future
 * (or present) expiry date is set, unrestricted if no expiry date is set.
 */
class SyncAssetRightsStatusCommand extends Command
{
    protected $signature = 'assets:sync-rights-status
        {--space= : Limit to a single space (id or slug)}
        {--dry-run : Report how many assets would change without updating them}';

    protected $description = "Recompute each asset's rights_status from its license_expires_at date";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no assets will be updated');
        }

        $query = Space::query()->with('defaultConnection');

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $spacesProcessed = 0;
        $totalUpdated = 0;
        $totalFailed = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($dryRun, &$spacesProcessed, &$totalUpdated, &$totalFailed) {
            foreach ($spaces as $space) {
                try {
                    $updated = $this->syncSpace($space, $dryRun);
                    $totalUpdated += $updated;
                    $spacesProcessed++;

                    if ($updated > 0) {
                        $this->line(sprintf(
                            '  %s  %s %d asset(s)',
                            str_pad($space->id, 28),
                            $dryRun ? 'would update' : 'updated',
                            $updated
                        ));
                    }
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $this->error("  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to sync asset rights status for space', [
                        'space' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->newLine();
        $this->info("Processed {$spacesProcessed} space(s); {$totalUpdated} asset(s) " . ($dryRun ? 'would be updated' : 'updated') . "; {$totalFailed} space(s) failed.");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function syncSpace(Space $space, bool $dryRun): int
    {
        app()->offsetSet('currentSpace', $space);

        $updated = 0;

        $expiredQuery = Asset::query()
            ->whereNotNull('license_expires_at')
            ->where('license_expires_at', '<', now())
            ->where('rights_status', '!=', AssetRightsStatus::EXPIRED->value);
        $updated += $dryRun ? $expiredQuery->count() : $expiredQuery->update(['rights_status' => AssetRightsStatus::EXPIRED->value]);

        $restrictedQuery = Asset::query()
            ->whereNotNull('license_expires_at')
            ->where('license_expires_at', '>=', now())
            ->where('rights_status', '!=', AssetRightsStatus::RESTRICTED->value);
        $updated += $dryRun ? $restrictedQuery->count() : $restrictedQuery->update(['rights_status' => AssetRightsStatus::RESTRICTED->value]);

        $unrestrictedQuery = Asset::query()
            ->whereNull('license_expires_at')
            ->where('rights_status', '!=', AssetRightsStatus::UNRESTRICTED->value);
        $updated += $dryRun ? $unrestrictedQuery->count() : $unrestrictedQuery->update(['rights_status' => AssetRightsStatus::UNRESTRICTED->value]);

        return $updated;
    }
}
