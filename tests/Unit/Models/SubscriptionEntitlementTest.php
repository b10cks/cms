<?php

namespace Tests\Unit\Models;

use App\Models\Management\Space;
use App\Models\Management\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function subscription(array $overrides = []): Subscription
    {
        return Subscription::factory()->create(array_merge([
            'space_id' => Space::factory()->create()->id,
            'status' => 'active',
            'ends_at' => null,
        ], $overrides));
    }

    #[Test]
    public function active_and_trial_subscriptions_grant_entitlements(): void
    {
        $this->assertTrue($this->subscription(['status' => 'active'])->isActive());
        $this->assertTrue($this->subscription(['status' => 'on_trial'])->isActive());
    }

    #[Test]
    public function a_cancelled_subscription_keeps_entitlements_until_period_end(): void
    {
        $inGrace = $this->subscription(['status' => 'cancelled', 'ends_at' => now()->addDays(10)]);
        $lapsed = $this->subscription(['status' => 'cancelled', 'ends_at' => now()->subDay()]);
        $noEnd = $this->subscription(['status' => 'cancelled', 'ends_at' => null]);

        $this->assertTrue($inGrace->isActive());
        $this->assertTrue($inGrace->isCancelledWithGrace());
        $this->assertFalse($lapsed->isActive());
        $this->assertFalse($noEnd->isActive());
    }

    #[Test]
    public function past_due_keeps_entitlements_during_dunning(): void
    {
        $this->assertTrue($this->subscription(['status' => 'past_due'])->isActive());
        $this->assertFalse($this->subscription(['status' => 'unpaid'])->isActive());
        $this->assertFalse($this->subscription(['status' => 'expired'])->isActive());
        $this->assertFalse($this->subscription(['status' => 'paused'])->isActive());
    }

    #[Test]
    public function the_active_scope_matches_the_entitlement_semantics(): void
    {
        $active = $this->subscription(['status' => 'active']);
        $grace = $this->subscription(['status' => 'cancelled', 'ends_at' => now()->addDay()]);
        $pastDue = $this->subscription(['status' => 'past_due']);
        $lapsed = $this->subscription(['status' => 'cancelled', 'ends_at' => now()->subDay()]);
        $expired = $this->subscription(['status' => 'expired']);

        $ids = Subscription::query()->active()->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertContains($grace->id, $ids);
        $this->assertContains($pastDue->id, $ids);
        $this->assertNotContains($lapsed->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    #[Test]
    public function quotas_act_as_an_override_over_plan_defaults(): void
    {
        $plan = \App\Models\Management\Plan::create([
            'name' => ['default' => 'Pro'],
            'price' => 10,
            'period' => 'month',
            'quotas' => ['traffic' => 1000],
            'is_free' => false,
            'is_active' => true,
        ]);

        $default = $this->subscription(['plan_id' => $plan->id, 'quotas' => null]);
        $custom = $this->subscription(['plan_id' => $plan->id, 'quotas' => ['traffic' => 5000]]);

        $this->assertSame(['traffic' => 1000], $default->effectiveQuotas());
        $this->assertSame(['traffic' => 5000], $custom->effectiveQuotas());
    }
}
