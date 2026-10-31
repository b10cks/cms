<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\Provider\ProviderMetricsController;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Models\Management\SubscriptionPeriod;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Provider\ProviderMetricsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProviderMetricsController::class)]
#[CoversClass(ProviderMetricsService::class)]
class ProviderMetricsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Months of the default 12m range: 2025-11 is index 0, 2026-10 index 11. */
    private const JUL = 8;

    private const AUG = 9;

    private const SEP = 10;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->actingAs(User::factory()->create(['is_root' => true, 'created_at' => '2025-01-01 00:00:00']));

        $this->pro = Plan::query()->create([
            'name' => ['en' => 'Pro', 'default' => 'Pro'], 'price' => 100, 'yearly_price' => 1200, 'period' => 'month',
            'quotas' => ['aiCredit' => 10], 'is_active' => true, 'is_public' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function revenue_normalises_yearly_plans_and_splits_movement_per_account(): void
    {
        $agency = Team::factory()->create(['parent_id' => null]);
        $client = Team::factory()->create(['parent_id' => $agency->id]);
        $other = Team::factory()->create(['parent_id' => null]);
        $business = Plan::query()->create(['name' => ['en' => 'Business', 'default' => 'Business'], 'price' => 300, 'period' => 'month', 'quotas' => []]);

        // The agency pays 100 from July and adds a yearly client space in
        // September (1200 / 12 = 100 more): expansion, not a new account.
        $this->period($this->space($agency), $this->pro, '2026-07-10', price: 100);
        $this->period($this->space($client), $this->pro, '2026-09-05', price: 1200, interval: 'year');
        // Another account pays 300 from August and leaves in September.
        $this->period($this->space($other), $business, '2026-08-03', price: 300, endedAt: '2026-09-20');
        // Still on trial: no revenue yet.
        $this->period($this->space($other), $this->pro, '2026-10-01', price: 100, trialEndsAt: '2026-10-20');

        $revenue = $this->getJson(route('mgmt.provider.metrics'))->assertOk()->json('data.revenue');

        $this->assertEqualsWithDelta(200, $revenue['mrr']['current'], 0.001);
        $this->assertEqualsWithDelta(200, $revenue['mrr']['previous'], 0.001);
        $this->assertEqualsWithDelta(2400, $revenue['arr'], 0.001);
        $this->assertEquals([100, 300], [$revenue['movement']['new'][self::JUL], $revenue['movement']['new'][self::AUG]]);
        $this->assertEquals(100, $revenue['movement']['expansion'][self::SEP]);
        $this->assertEquals(-300, $revenue['movement']['churn'][self::SEP]);
        $this->assertEqualsWithDelta(-200, $revenue['net_new_mrr']['series'][self::SEP], 0.001);
        $this->assertEqualsWithDelta(0.75, $revenue['revenue_churn']['series'][self::SEP], 0.001);
        $this->assertEqualsWithDelta(0.5, $revenue['logo_churn']['series'][self::SEP], 0.001);
        $this->assertEqualsWithDelta(2, $revenue['paying_spaces']['current'], 0.001);
        $this->assertEqualsWithDelta(1, $revenue['paying_teams']['current'], 0.001);
        $this->assertEqualsWithDelta(200, $revenue['arpa']['current'], 0.001);
        $this->assertEquals([['name' => 'Pro', 'mrr' => 200]], $revenue['by_plan']);

        $cohorts = collect($revenue['retention'])->keyBy('month');
        $this->assertEquals([1, 1, 2, 2], $cohorts['2026-07']['values']);
        $this->assertEquals([1, 0, 0], $cohorts['2026-08']['values']);
        $this->assertSame([], $cohorts['2026-09']['values']);
    }

    #[Test]
    public function self_hosted_omits_revenue(): void
    {
        config(['edition.features.billing' => false]);

        $data = $this->getJson(route('mgmt.provider.metrics', ['range' => '6m']))->assertOk()->json('data');

        $this->assertArrayNotHasKey('revenue', $data);
        $this->assertCount(6, $data['months']);
        $this->assertArrayHasKey('engagement', $data);
    }

    #[Test]
    public function only_root_users_can_read_metrics(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson(route('mgmt.provider.metrics'))->assertForbidden();
    }

    private function space(Team $team): Space
    {
        return Space::factory()->create(['team_id' => $team->id, 'created_at' => '2025-06-01 00:00:00']);
    }

    private function period(Space $space, Plan $plan, string $startedAt, float $price, string $interval = 'month', ?string $endedAt = null, ?string $trialEndsAt = null): void
    {
        $subscription = Subscription::withoutEvents(fn () => Subscription::factory()->create([
            'space_id' => $space->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_interval' => $interval, 'trial_ends_at' => $trialEndsAt,
        ]));

        SubscriptionPeriod::query()->create([
            'space_id' => $space->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'plan_name' => $plan->getTranslatedName(), 'quotas' => $plan->quotas, 'price' => $price,
            'billing_period' => $interval, 'status' => 'active', 'started_at' => $startedAt, 'ended_at' => $endedAt,
        ]);
    }
}
