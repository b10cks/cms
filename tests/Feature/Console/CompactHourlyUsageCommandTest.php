<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CompactHourlyUsageCommand;
use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CompactHourlyUsageCommand::class)]
class CompactHourlyUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
    }

    private function createApiHit(Carbon $timestamp, array $attributes = []): SpaceApiHitHourly
    {
        $record = new SpaceApiHitHourly;
        $record->forceFill([
            'space_id' => $this->space->id,
            'hour_timestamp' => $timestamp,
            'hit_count' => 10,
            'unique_ips' => 5,
            'success_count' => 8,
            'error_count' => 2,
            'time_taken_sum' => 1000,
            'status_code_distribution' => ['200' => 8, '404' => 2],
            ...$attributes,
        ])->save();

        return $record;
    }

    private function createTraffic(Carbon $timestamp, array $attributes = []): SpaceTrafficUsageHourly
    {
        $record = new SpaceTrafficUsageHourly;
        $record->forceFill([
            'space_id' => $this->space->id,
            'hour_timestamp' => $timestamp,
            'bytes_sent' => 1000,
            'bytes_received' => 100,
            'request_count' => 10,
            'cache_hits' => 7,
            'cache_misses' => 3,
            ...$attributes,
        ])->save();

        return $record;
    }

    #[Test]
    public function it_folds_old_hourly_api_hits_into_one_daily_row(): void
    {
        $day = now()->subDays(100)->startOfDay();

        $this->createApiHit($day->copy()->addHours(0), ['hit_count' => 10, 'unique_ips' => 5, 'time_taken_sum' => 500]);
        $this->createApiHit($day->copy()->addHours(8), ['hit_count' => 20, 'unique_ips' => 12, 'time_taken_sum' => 1500, 'status_code_distribution' => ['200' => 15, '500' => 5], 'success_count' => 15, 'error_count' => 5]);
        $this->createApiHit($day->copy()->addHours(23), ['hit_count' => 30, 'unique_ips' => 3, 'time_taken_sum' => 1000, 'status_code_distribution' => null, 'success_count' => 30, 'error_count' => 0]);

        $this->artisan('usage:compact-hourly', ['--days' => 90])->assertExitCode(0);

        $rows = SpaceApiHitHourly::where('space_id', $this->space->id)->get();

        $this->assertCount(1, $rows);

        $daily = $rows->first();
        $this->assertTrue($daily->hour_timestamp->eq($day));
        $this->assertSame(60, $daily->hit_count);
        $this->assertSame(12, $daily->unique_ips);
        $this->assertSame(53, $daily->success_count);
        $this->assertSame(7, $daily->error_count);
        $this->assertSame(3000, $daily->time_taken_sum);
        $this->assertEquals(['200' => 23, '404' => 2, '500' => 5], $daily->status_code_distribution);
    }

    #[Test]
    public function it_folds_old_hourly_traffic_into_one_daily_row(): void
    {
        $day = now()->subDays(100)->startOfDay();

        $this->createTraffic($day->copy()->addHours(1), ['bytes_sent' => 1000, 'bytes_received' => 100]);
        $this->createTraffic($day->copy()->addHours(2), ['bytes_sent' => 2000, 'bytes_received' => 200, 'request_count' => 20, 'cache_hits' => 20, 'cache_misses' => 0]);

        $this->artisan('usage:compact-hourly', ['--days' => 90])->assertExitCode(0);

        $rows = SpaceTrafficUsageHourly::where('space_id', $this->space->id)->get();

        $this->assertCount(1, $rows);

        $daily = $rows->first();
        $this->assertTrue($daily->hour_timestamp->eq($day));
        $this->assertSame(3000, $daily->bytes_sent);
        $this->assertSame(300, $daily->bytes_received);
        $this->assertSame(3300, $daily->total_bytes);
        $this->assertSame(30, $daily->request_count);
        $this->assertSame(27, $daily->cache_hits);
        $this->assertSame(3, $daily->cache_misses);
    }

    #[Test]
    public function it_leaves_rows_inside_the_retention_window_untouched(): void
    {
        $day = now()->subDays(5)->startOfDay();

        $this->createApiHit($day->copy()->addHours(1));
        $this->createApiHit($day->copy()->addHours(2));
        $this->createTraffic($day->copy()->addHours(3));

        $this->artisan('usage:compact-hourly', ['--days' => 90])->assertExitCode(0);

        $this->assertSame(2, SpaceApiHitHourly::count());
        $this->assertSame(1, SpaceTrafficUsageHourly::count());
    }

    #[Test]
    public function it_is_idempotent_across_repeated_runs(): void
    {
        $day = now()->subDays(100)->startOfDay();

        $this->createApiHit($day->copy()->addHours(3), ['hit_count' => 10]);
        $this->createApiHit($day->copy()->addHours(4), ['hit_count' => 20]);

        $this->artisan('usage:compact-hourly', ['--days' => 90])->assertExitCode(0);
        $this->artisan('usage:compact-hourly', ['--days' => 90])->assertExitCode(0);

        $rows = SpaceApiHitHourly::where('space_id', $this->space->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(30, $rows->first()->hit_count);
    }

    #[Test]
    public function it_normalizes_a_lone_non_midnight_row_to_midnight(): void
    {
        $day = now()->subDays(100)->startOfDay();

        $this->createApiHit($day->copy()->addHours(17), ['hit_count' => 42]);

        $this->artisan('usage:compact-hourly', ['--days' => 90])->assertExitCode(0);

        $rows = SpaceApiHitHourly::where('space_id', $this->space->id)->get();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->hour_timestamp->eq($day));
        $this->assertSame(42, $rows->first()->hit_count);
    }

    #[Test]
    public function it_compacts_per_space_and_day(): void
    {
        $otherSpace = Space::factory()->create();
        $dayOne = now()->subDays(100)->startOfDay();
        $dayTwo = now()->subDays(99)->startOfDay();

        $this->createApiHit($dayOne->copy()->addHours(1), ['hit_count' => 1]);
        $this->createApiHit($dayOne->copy()->addHours(2), ['hit_count' => 2]);
        $this->createApiHit($dayTwo->copy()->addHours(1), ['hit_count' => 4]);
        $this->createApiHit($dayOne->copy()->addHours(1), ['space_id' => $otherSpace->id, 'hit_count' => 8]);
        $this->createApiHit($dayOne->copy()->addHours(2), ['space_id' => $otherSpace->id, 'hit_count' => 16]);

        $this->artisan('usage:compact-hourly', ['--days' => 90])->assertExitCode(0);

        $this->assertSame(3, SpaceApiHitHourly::count());
        $this->assertSame(3, (int) SpaceApiHitHourly::where('space_id', $this->space->id)->where('hour_timestamp', $dayOne)->value('hit_count'));
        $this->assertSame(4, (int) SpaceApiHitHourly::where('space_id', $this->space->id)->where('hour_timestamp', $dayTwo)->value('hit_count'));
        $this->assertSame(24, (int) SpaceApiHitHourly::where('space_id', $otherSpace->id)->where('hour_timestamp', $dayOne)->value('hit_count'));
    }

    #[Test]
    public function dry_run_reports_without_modifying_anything(): void
    {
        $day = now()->subDays(100)->startOfDay();

        $this->createApiHit($day->copy()->addHours(1));
        $this->createApiHit($day->copy()->addHours(2));

        $this->artisan('usage:compact-hourly', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('1 row(s) would be removed')
            ->assertExitCode(0);

        $this->assertSame(2, SpaceApiHitHourly::count());
    }
}
