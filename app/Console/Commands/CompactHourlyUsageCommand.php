<?php

namespace App\Console\Commands;

use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Folds hourly usage rows older than the retention window into a single row
 * per space and day, anchored at 00:00. Since every reader aggregates by day
 * or coarser (and the unique key is space_id + hour_timestamp), a compacted
 * day is indistinguishable from an uncompacted one to existing queries.
 * Re-running on already-compacted days is a no-op.
 */
class CompactHourlyUsageCommand extends Command
{
    protected $signature = 'usage:compact-hourly
        {--days= : Compact days older than this many days (defaults to services.cloudfront.usage.hourly_retention_days)}
        {--dry-run : Report what would be compacted without writing anything}';

    protected $description = 'Fold hourly API-hit and traffic usage rows older than the retention window into one daily row per space';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('services.cloudfront.usage.hourly_retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->startOfDay()->subDays($days);

        if ($dryRun) {
            $this->warn('DRY RUN - nothing will be written');
        }

        [$hitDays, $hitRows] = $this->compact(SpaceApiHitHourly::class, $cutoff, $dryRun, $this->aggregateApiHits(...));
        [$trafficDays, $trafficRows] = $this->compact(SpaceTrafficUsageHourly::class, $cutoff, $dryRun, $this->aggregateTraffic(...));

        $verb = $dryRun ? 'would be removed' : 'removed';
        $this->info("API hits: {$hitDays} day(s) compacted, {$hitRows} row(s) {$verb}.");
        $this->info("Traffic: {$trafficDays} day(s) compacted, {$trafficRows} row(s) {$verb}.");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<SpaceApiHitHourly|SpaceTrafficUsageHourly>  $model
     * @param  \Closure(Collection<int, SpaceApiHitHourly|SpaceTrafficUsageHourly>): array<string, mixed>  $aggregate
     * @return array{0: int, 1: int} days compacted, hourly rows removed
     */
    private function compact(string $model, Carbon $cutoff, bool $dryRun, \Closure $aggregate): array
    {
        $candidates = $model::query()
            ->selectRaw('space_id, DATE(hour_timestamp) as day, COUNT(*) as row_count')
            ->where('hour_timestamp', '<', $cutoff)
            ->groupByRaw('space_id, DATE(hour_timestamp)')
            ->havingRaw("COUNT(*) > 1 OR MAX(TIME(hour_timestamp)) > '00:00:00'")
            ->get();

        $daysCompacted = 0;
        $rowsRemoved = 0;

        foreach ($candidates as $candidate) {
            $dayStart = Carbon::parse($candidate->day)->startOfDay();
            $daysCompacted++;

            if ($dryRun) {
                $rowsRemoved += (int) $candidate->row_count - 1;

                continue;
            }

            DB::transaction(function () use ($model, $candidate, $dayStart, $aggregate, &$rowsRemoved) {
                $rows = $model::query()
                    ->where('space_id', $candidate->space_id)
                    ->where('hour_timestamp', '>=', $dayStart)
                    ->where('hour_timestamp', '<', $dayStart->copy()->addDay())
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    return;
                }

                $attributes = $aggregate($rows);

                $model::query()->whereKey($rows->modelKeys())->delete();

                (new $model)->forceFill([
                    'space_id' => $candidate->space_id,
                    'hour_timestamp' => $dayStart,
                    ...$attributes,
                ])->save();

                $rowsRemoved += $rows->count() - 1;
            });
        }

        return [$daysCompacted, $rowsRemoved];
    }

    /**
     * @param  Collection<int, SpaceApiHitHourly>  $rows
     * @return array<string, mixed>
     */
    private function aggregateApiHits(Collection $rows): array
    {
        $distribution = [];

        foreach ($rows as $row) {
            foreach ($row->status_code_distribution ?? [] as $code => $count) {
                $distribution[$code] = ($distribution[$code] ?? 0) + $count;
            }
        }

        return [
            'hit_count' => (int) $rows->sum('hit_count'),
            // Hourly counts can't be deduplicated across hours; the busiest
            // hour is the tightest lower bound for the day's unique IPs.
            'unique_ips' => (int) $rows->max('unique_ips'),
            'success_count' => (int) $rows->sum('success_count'),
            'error_count' => (int) $rows->sum('error_count'),
            'time_taken_sum' => (int) $rows->sum('time_taken_sum'),
            'status_code_distribution' => $distribution ?: null,
        ];
    }

    /**
     * @param  Collection<int, SpaceTrafficUsageHourly>  $rows
     * @return array<string, mixed>
     */
    private function aggregateTraffic(Collection $rows): array
    {
        return [
            'bytes_sent' => (int) $rows->sum('bytes_sent'),
            'bytes_received' => (int) $rows->sum('bytes_received'),
            'request_count' => (int) $rows->sum('request_count'),
            'cache_hits' => (int) $rows->sum('cache_hits'),
            'cache_misses' => (int) $rows->sum('cache_misses'),
        ];
    }
}
