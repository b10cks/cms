<?php

namespace Tests\Unit\Services\Subscription;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Management\Subscription;
use App\Services\Subscription\SubscriptionPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Stop the subscription model's saved-hook jobs (AI key sync + period
        // reconcile) from running so we drive reconcile() explicitly.
        Bus::fake();
    }

    private function service(): SubscriptionPeriodService
    {
        return app(SubscriptionPeriodService::class);
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => ['en' => 'Pro', 'default' => 'Pro'],
            'price' => 10,
            'period' => 'month',
            'quotas' => ['storage' => 1000, 'traffic' => 2000, 'aiCredit' => 5.0],
            'is_free' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function subscription(Space $space, Plan $plan, array $overrides = []): Subscription
    {
        return Subscription::factory()->create(array_merge([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'quotas' => $plan->quotas,
            'renews_at' => now()->addMonth(),
            'ends_at' => null,
        ], $overrides));
    }

    #[Test]
    public function it_opens_a_period_when_a_plan_activates(): void
    {
        $space = Space::factory()->create();
        $plan = $this->plan();
        $this->subscription($space, $plan);

        $this->service()->reconcile($space);

        $periods = $space->subscriptionPeriods()->get();
        $this->assertCount(1, $periods);

        $period = $periods->first();
        $this->assertSame($plan->id, $period->plan_id);
        $this->assertSame($plan->getTranslatedName(), $period->plan_name);
        $this->assertSame($plan->quotas, $period->quotas);
        $this->assertNull($period->ended_at);
        $this->assertNull($period->close_reason);
        $this->assertTrue($period->isOpen());
    }

    #[Test]
    public function reconcile_is_idempotent(): void
    {
        $space = Space::factory()->create();
        $this->subscription($space, $this->plan());

        $this->service()->reconcile($space);
        $this->service()->reconcile($space);
        $this->service()->reconcile($space);

        $this->assertSame(1, $space->subscriptionPeriods()->count());
    }

    #[Test]
    public function it_closes_and_opens_a_new_period_on_an_upgrade(): void
    {
        $space = Space::factory()->create();
        $free = $this->plan(['name' => ['default' => 'Free'], 'price' => 0, 'is_free' => true]);
        $pro = $this->plan(['name' => ['default' => 'Pro'], 'price' => 25]);

        $subscription = $this->subscription($space, $free, ['renews_at' => null]);
        $this->service()->reconcile($space);

        $subscription->update(['plan_id' => $pro->id, 'renews_at' => now()->addMonth()]);
        Cache::put("space.usage.storage.{$space->id}", 0, 120);
        $this->service()->reconcile($space);

        $closed = $space->subscriptionPeriods()->whereNotNull('ended_at')->first();
        $open = $space->subscriptionPeriods()->whereNull('ended_at')->first();

        $this->assertSame('upgraded', $closed->close_reason);
        $this->assertSame($free->id, $closed->plan_id);
        $this->assertNotNull($closed->ended_at);

        $this->assertSame($pro->id, $open->plan_id);
        $this->assertNull($open->close_reason);
    }

    #[Test]
    public function a_cheaper_plan_switch_is_recorded_as_a_downgrade(): void
    {
        $space = Space::factory()->create();
        $pro = $this->plan(['price' => 25]);
        $free = $this->plan(['price' => 0, 'is_free' => true]);

        $subscription = $this->subscription($space, $pro);
        $this->service()->reconcile($space);

        $subscription->update(['plan_id' => $free->id]);
        Cache::put("space.usage.storage.{$space->id}", 0, 120);
        $this->service()->reconcile($space);

        $closed = $space->subscriptionPeriods()->whereNotNull('ended_at')->first();
        $this->assertSame('downgraded', $closed->close_reason);
    }

    #[Test]
    public function it_rolls_over_when_the_billing_cycle_renews(): void
    {
        $space = Space::factory()->create();
        $plan = $this->plan();
        $subscription = $this->subscription($space, $plan, ['renews_at' => now()->addMonth()]);

        $this->service()->reconcile($space);

        $subscription->update(['renews_at' => now()->addMonths(2)]);
        Cache::put("space.usage.storage.{$space->id}", 0, 120);
        $this->service()->reconcile($space);

        $this->assertSame(2, $space->subscriptionPeriods()->count());

        $closed = $space->subscriptionPeriods()->whereNotNull('ended_at')->first();
        $open = $space->subscriptionPeriods()->whereNull('ended_at')->first();

        $this->assertSame('renewed', $closed->close_reason);
        $this->assertSame($plan->id, $open->plan_id);
        $this->assertTrue($open->renews_at->greaterThan($closed->renews_at));
    }

    #[Test]
    public function it_closes_the_open_period_when_the_subscription_is_cancelled(): void
    {
        $space = Space::factory()->create();
        $subscription = $this->subscription($space, $this->plan());
        $this->service()->reconcile($space);

        $subscription->update(['status' => 'cancelled']);
        Cache::put("space.usage.storage.{$space->id}", 0, 120);
        $this->service()->reconcile($space);

        $this->assertSame(0, $space->subscriptionPeriods()->whereNull('ended_at')->count());
        $this->assertSame('cancelled', $space->subscriptionPeriods()->first()->close_reason);
    }

    #[Test]
    public function closing_a_period_rolls_up_its_usage(): void
    {
        $space = Space::factory()->create();
        $plan = $this->plan();
        $subscription = $this->subscription($space, $plan, ['status' => 'cancelled']);

        // A period that has been open for the last 5 days.
        $period = $space->subscriptionPeriods()->create([
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'plan_name' => 'Pro',
            'quotas' => $plan->quotas,
            'price' => $plan->price,
            'billing_period' => 'month',
            'status' => 'active',
            'started_at' => now()->subDays(5),
            'renews_at' => null,
            'ended_at' => null,
        ]);

        Cache::put("space.usage.storage.{$space->id}", 750, 120);

        SpaceTrafficUsageHourly::create([
            'space_id' => $space->id,
            'hour_timestamp' => now()->subDays(2),
            'bytes_sent' => 800,
            'bytes_received' => 200,
            'request_count' => 5,
            'cache_hits' => 0,
            'cache_misses' => 0,
        ]);

        $this->service()->reconcile($space);

        $period->refresh();
        $this->assertSame('cancelled', $period->close_reason);
        $this->assertSame(750, $period->storage_bytes);
        $this->assertSame(1000, $period->traffic_bytes);
        $this->assertSame('0.000000', $period->ai_spend_usd);
    }

    #[Test]
    public function a_lapsed_subscription_falls_back_to_the_free_plan(): void
    {
        $space = Space::factory()->create();
        $free = $this->plan([
            'name' => ['en' => 'Free', 'default' => 'Free'],
            'price' => 0,
            'is_free' => true,
            'quotas' => ['traffic' => 100],
        ]);
        $subscription = $this->subscription($space, $this->plan(), ['status' => 'expired']);

        Cache::put("space.usage.storage.{$space->id}", 0, 120);
        $this->service()->reconcile($space);

        $enrolled = $space->subscriptions()->where('plan_id', $free->id)->first();
        $this->assertNotNull($enrolled);
        $this->assertSame('active', $enrolled->status);
        $this->assertNull($enrolled->quotas);
        $this->assertSame(['traffic' => 100], $enrolled->effectiveQuotas());
    }

    #[Test]
    public function a_space_without_any_subscription_is_enrolled_on_the_free_plan(): void
    {
        $space = Space::factory()->create();
        $free = $this->plan([
            'name' => ['en' => 'Free', 'default' => 'Free'],
            'price' => 0,
            'is_free' => true,
            'quotas' => ['traffic' => 100],
        ]);

        $this->service()->reconcile($space);

        $enrolled = $space->subscriptions()->where('plan_id', $free->id)->first();
        $this->assertNotNull($enrolled);
        $this->assertSame('active', $enrolled->status);
        $this->assertSame(['traffic' => 100], $enrolled->effectiveQuotas());
    }

    #[Test]
    public function a_pending_checkout_blocks_the_free_plan_fallback(): void
    {
        $space = Space::factory()->create();
        $free = $this->plan(['name' => ['default' => 'Free'], 'price' => 0, 'is_free' => true]);
        $paid = $this->plan();

        $this->subscription($space, $paid, ['status' => 'expired']);
        $this->subscription($space, $paid, ['status' => 'pending', 'lemon_squeezy_id' => null]);

        Cache::put("space.usage.storage.{$space->id}", 0, 120);
        $this->service()->reconcile($space);

        $this->assertNull($space->subscriptions()->where('plan_id', $free->id)->first());
    }

    #[Test]
    public function a_cancelled_subscription_in_grace_keeps_its_period_open(): void
    {
        $space = Space::factory()->create();
        $subscription = $this->subscription($space, $this->plan());
        $this->service()->reconcile($space);

        // Cancelled at LemonSqueezy, but paid through the period end.
        $subscription->update(['status' => 'cancelled', 'ends_at' => now()->addDays(12)]);
        $this->service()->reconcile($space);

        $this->assertSame(1, $space->subscriptionPeriods()->whereNull('ended_at')->count());
    }

    #[Test]
    public function no_period_is_opened_without_an_active_subscription(): void
    {
        $space = Space::factory()->create();
        $this->subscription($space, $this->plan(), ['status' => 'cancelled']);

        $this->service()->reconcile($space);

        $this->assertSame(0, $space->subscriptionPeriods()->count());
    }
}
