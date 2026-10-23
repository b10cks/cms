<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\SpaceModel;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off backfill for purify-on-write: asset filenames and folder names and
 * descriptions used to be purified on every read, so rows written through
 * raw queries may still hold unpurified values. Rewrites only rows whose
 * purified value differs, without touching updated_at. Safe to re-run.
 */
class PurifyAssetAttributesCommand extends Command
{
    protected $signature = 'assets:purify-attributes
        {--space= : Limit to a single space (id or slug)}
        {--dry-run : Report how many rows would change without updating them}';

    protected $description = 'Purify stored asset filenames and folder names/descriptions';

    /** @var array<class-string<SpaceModel>, list<string>> */
    private const COLUMNS = [
        Asset::class => ['filename'],
        AssetFolder::class => ['name', 'description'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no rows will be updated');
        }

        $query = Space::query()->with('defaultConnection');

        if ($spaceArg = $this->option('space')) {
            $query->where(fn ($q) => $q->where('id', $spaceArg)->orWhere('slug', $spaceArg));
        }

        $spacesProcessed = 0;
        $totalChanged = 0;
        $totalFailed = 0;

        $query->orderBy('id')->chunkById(100, function ($spaces) use ($dryRun, &$spacesProcessed, &$totalChanged, &$totalFailed) {
            foreach ($spaces as $space) {
                try {
                    $counts = $this->purifySpace($space, $dryRun);
                    $totalChanged += array_sum($counts);
                    $spacesProcessed++;

                    if (array_sum($counts) > 0 || $dryRun) {
                        $this->line(sprintf(
                            '  %s  %s %d asset(s), %d folder(s)',
                            str_pad($space->id, 28),
                            $dryRun ? 'would update' : 'updated',
                            $counts[Asset::class],
                            $counts[AssetFolder::class],
                        ));
                    }
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $this->error("  {$space->id}: {$e->getMessage()}");
                    Log::error('Failed to purify asset attributes for space', [
                        'space' => $space->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->newLine();
        $this->info("Processed {$spacesProcessed} space(s); {$totalChanged} row(s) " . ($dryRun ? 'would be updated' : 'updated') . "; {$totalFailed} space(s) failed.");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<class-string<SpaceModel>, int> changed rows per model
     */
    private function purifySpace(Space $space, bool $dryRun): array
    {
        $restore = SpaceContext::enter($space);

        try {
            $counts = [];

            foreach (self::COLUMNS as $model => $columns) {
                $counts[$model] = 0;

                $model::query()->withTrashed()->toBase()
                    ->select(['id', ...$columns])
                    ->chunkById(500, function ($rows) use ($model, $dryRun, &$counts) {
                        foreach ($rows as $row) {
                            $raw = (array) $row;
                            unset($raw['id']);

                            $changes = array_diff_assoc($model::purifyRow($raw), $raw);

                            if ($changes === []) {
                                continue;
                            }

                            $counts[$model]++;

                            if (! $dryRun) {
                                $model::query()->withTrashed()->toBase()->where('id', $row->id)->update($changes);
                            }
                        }
                    });
            }

            return $counts;
        } finally {
            $restore();
        }
    }
}
