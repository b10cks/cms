<?php

namespace Tests\Unit\Services\Space;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Management\Subscription;
use App\Services\Ai\Dto\AiUsageDto;
use App\Services\Ai\SpaceAiUsageService;
use App\Services\Space\SpaceUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaceUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function space(): Space
    {
        // Persisted so the traffic/api-hit foreign keys resolve; the
        // subscription relation is then stubbed in-memory.
        return Space::factory()->create();
    }

    private function withSubscription(Space $space, ?array $quotas): void
    {
        $plan = (new Plan)->forceFill(['is_free' => true, 'quotas' => $quotas]);
        $subscription = (new Subscription)->forceFill(['status' => 'active']);
        $subscription->setRelation('plan', $plan);
        $space->setRelation('subscriptions', collect([$subscription]));
    }

    private function service(AiUsageDto $aiUsage): SpaceUsageService
    {
        $ai = Mockery::mock(SpaceAiUsageService::class);
        $ai->shouldReceive('forSpace')->andReturn($aiUsage);

        return new SpaceUsageService($ai);
    }

    #[Test]
    public function it_assembles_usage_across_all_dimensions(): void
    {
        $space = $this->space();
        $this->withSubscription($space, [
            'storage' => 1000,
            'traffic' => 2000,
            'requests' => 100,
            'aiCredit' => 5.0,
        ]);

        // Storage is read through a cache; seeding it bypasses the per-space DB.
        Cache::put("space.usage.storage.{$space->id}", 750, 120);

        // total_bytes is a generated column (sent + received); set the inputs.
        SpaceTrafficUsageHourly::create([
            'space_id' => $space->id,
            'hour_timestamp' => now()->startOfMonth(),
            'bytes_sent' => 1000,
            'bytes_received' => 500,
            'request_count' => 10,
            'cache_hits' => 0,
            'cache_misses' => 0,
        ]);

        SpaceApiHitHourly::create([
            'space_id' => $space->id,
            'hour_timestamp' => now()->startOfMonth(),
            'hit_count' => 42,
        ]);

        $result = $this->service(
            new AiUsageDto(provider: 'openrouter', unit: 'usd', available: true, used: 2.5, limit: 5.0)
        )->forSpace($space);

        $this->assertSame(750, $result['storage']->toArray()['used']);
        $this->assertSame(75, $result['storage']->percentage());

        $this->assertSame(1500, $result['traffic']->toArray()['used']);
        $this->assertSame(75, $result['traffic']->percentage());

        $this->assertSame(42, $result['requests']->toArray()['used']);
        $this->assertSame(42, $result['requests']->percentage());

        $this->assertSame(2.5, $result['ai']->toArray()['used']);
        $this->assertSame(5.0, $result['ai']->limit);
        $this->assertSame(50, $result['ai']->percentage());

        $this->assertArrayHasKey('resets_at', $result['period']);
    }

    #[Test]
    public function traffic_and_requests_outside_the_current_month_are_excluded(): void
    {
        $space = $this->space();
        $this->withSubscription($space, ['traffic' => 2000, 'requests' => 100]);
        Cache::put("space.usage.storage.{$space->id}", 0, 120);

        // Last month — must not count. (total_bytes is generated from sent+received)
        SpaceTrafficUsageHourly::create([
            'space_id' => $space->id,
            'hour_timestamp' => now()->startOfMonth()->subMonth(),
            'bytes_sent' => 9999,
            'bytes_received' => 0,
            'request_count' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
        ]);
        // This month — counts.
        SpaceTrafficUsageHourly::create([
            'space_id' => $space->id,
            'hour_timestamp' => now()->startOfMonth(),
            'bytes_sent' => 100,
            'bytes_received' => 0,
            'request_count' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
        ]);

        SpaceApiHitHourly::create([
            'space_id' => $space->id,
            'hour_timestamp' => now()->startOfMonth()->subMonth(),
            'hit_count' => 500,
        ]);
        SpaceApiHitHourly::create([
            'space_id' => $space->id,
            'hour_timestamp' => now()->startOfMonth(),
            'hit_count' => 7,
        ]);

        $result = $this->service(
            new AiUsageDto(provider: 'openrouter', unit: 'usd', available: false)
        )->forSpace($space);

        $this->assertSame(100, $result['traffic']->toArray()['used']);
        $this->assertSame(7, $result['requests']->toArray()['used']);
    }

    #[Test]
    public function null_quotas_yield_unlimited_metrics(): void
    {
        $space = $this->space();
        $this->withSubscription($space, null);
        Cache::put("space.usage.storage.{$space->id}", 12345, 120);

        $result = $this->service(
            AiUsageDto::unlimited('openrouter', 'usd', 'monthly', used: 3.0, live: true)
        )->forSpace($space);

        $this->assertTrue($result['storage']->unlimited());
        $this->assertTrue($result['traffic']->unlimited());
        $this->assertTrue($result['requests']->unlimited());
        $this->assertTrue($result['ai']->unlimited());
        $this->assertSame(12345, $result['storage']->toArray()['used']);
        $this->assertSame(0, $result['storage']->percentage());
    }
}
