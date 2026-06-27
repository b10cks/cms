<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Management\Subscription;
use App\Models\User;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class SpaceUsageHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
    }

    private function period(array $overrides = []): void
    {
        $this->space->subscriptionPeriods()->create(array_merge([
            'plan_name' => 'Pro',
            'quotas' => ['storage' => 1000, 'traffic' => 2000, 'requests' => 100, 'aiCredit' => 5.0],
            'price' => 10,
            'billing_period' => 'month',
            'status' => 'active',
            'started_at' => now(),
            'ended_at' => null,
        ], $overrides));
    }

    #[Test]
    public function it_lists_periods_newest_first_with_usage_rollups(): void
    {
        $this->period([
            'started_at' => now()->subMonth(),
            'ended_at' => now()->subDay(),
            'close_reason' => 'upgraded',
            'storage_bytes' => 500,
            'traffic_bytes' => 1000,
            'requests_count' => 50,
            'ai_spend_usd' => 2.5,
        ]);
        $this->period(['started_at' => now()]); // current, open

        $this->actingAs($this->owner);
        $response = $this->getJson(route('mgmt.spaces.usage.history', $this->space));

        $response->assertOk();
        $response->assertJsonPath('data.0.is_open', true);
        $response->assertJsonPath('data.1.close_reason', 'upgraded');
        $response->assertJsonPath('data.1.usage.storage.used', 500);
        $response->assertJsonPath('data.1.usage.storage.percentage', 50);
        $response->assertJsonPath('data.1.usage.traffic.used', 1000);
        $response->assertJsonPath('data.0.usage.storage.used', null);
    }

    #[Test]
    public function it_returns_a_daily_timeseries_for_a_period(): void
    {
        $this->period(['started_at' => now()->subDays(5)]);
        $period = $this->space->subscriptionPeriods()->first();

        foreach ([2, 2, 4] as $i => $daysAgo) {
            SpaceTrafficUsageHourly::create([
                'space_id' => $this->space->id,
                'hour_timestamp' => now()->subDays($daysAgo)->addHours($i),
                'bytes_sent' => 100,
                'bytes_received' => 0,
                'request_count' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
            ]);
        }

        $this->actingAs($this->owner);
        $response = $this->getJson(route('mgmt.spaces.usage.history.timeseries', [$this->space, $period]).'?metric=traffic');

        $response->assertOk();
        $response->assertJsonPath('data.metric', 'traffic');
        // Two rows on one day (200), one on another (100) => two buckets.
        $response->assertJsonCount(2, 'data.points');
    }

    #[Test]
    public function timeseries_rejects_an_unknown_metric(): void
    {
        $this->period();
        $period = $this->space->subscriptionPeriods()->first();

        $this->actingAs($this->owner);
        $this->getJson(route('mgmt.spaces.usage.history.timeseries', [$this->space, $period]).'?metric=bogus')
            ->assertStatus(422);
    }

    #[Test]
    public function billing_history_is_forbidden_without_billing_access(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        $this->getJson(route('mgmt.spaces.usage.history', $this->space))->assertForbidden();
    }

    #[Test]
    public function invoices_are_empty_for_a_space_without_a_billing_customer(): void
    {
        $this->actingAs($this->owner);

        $response = $this->getJson(route('mgmt.spaces.invoices', $this->space));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    #[Test]
    public function invoices_are_listed_from_lemonsqueezy_for_a_paid_space(): void
    {
        // Suppress model events: we only need the row to exist for the customer
        // lookup, and Auditable would target the active space DB's audit log.
        Subscription::withoutEvents(fn () => Subscription::factory()->create([
            'space_id' => $this->space->id,
            'status' => 'active',
            'ls_customer_id' => '12345',
        ]));

        $this->mock(LemonSqueezyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('listInvoices')->once()->andReturn([['id' => 'inv_1']]);
            $mock->shouldReceive('normalizeInvoice')->andReturn([
                'id' => 'inv_1',
                'total' => 2500,
                'total_formatted' => '$25.00',
                'currency' => 'USD',
                'status' => 'paid',
                'status_formatted' => 'Paid',
                'refunded' => false,
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'billing_reason' => 'renewal',
                'invoice_url' => 'https://invoice.example/inv_1',
                'created_at' => now()->toIso8601String(),
            ]);
        });

        $this->actingAs($this->owner);
        $response = $this->getJson(route('mgmt.spaces.invoices', $this->space));

        $response->assertOk();
        $response->assertJsonPath('data.0.id', 'inv_1');
        $response->assertJsonPath('data.0.total_formatted', '$25.00');
        $response->assertJsonPath('data.0.invoice_url', 'https://invoice.example/inv_1');
    }
}
