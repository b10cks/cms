<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Services\Ai\PlanAiKeyResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanAiKeyResolverTest extends TestCase
{
    private function space(?Subscription $subscription): Space
    {
        $space = (new Space())->forceFill(['id' => 'space_1']);
        $space->setRelation('subscriptions', collect(array_filter([$subscription])));

        return $space;
    }

    private function subscription(array $attributes, Plan $plan): Subscription
    {
        $subscription = new Subscription($attributes);
        $subscription->setRelation('plan', $plan);

        return $subscription;
    }

    #[Test]
    public function a_space_without_a_subscription_is_not_eligible(): void
    {
        $spec = (new PlanAiKeyResolver())->resolve($this->space(null));

        $this->assertFalse($spec->eligible);
    }

    #[Test]
    public function an_active_free_plan_is_eligible_with_its_ai_credit_as_usd_limit(): void
    {
        $plan = new Plan(['is_free' => true, 'quotas' => ['aiCredit' => 1.0]]);
        $subscription = $this->subscription(['status' => 'active'], $plan);

        $spec = (new PlanAiKeyResolver())->resolve($this->space($subscription));

        $this->assertTrue($spec->eligible);
        $this->assertFalse($spec->unlimited);
        $this->assertSame(1.0, $spec->limit);
    }

    #[Test]
    public function a_paid_plan_without_a_live_lemonsqueezy_subscription_is_not_eligible(): void
    {
        $plan = new Plan(['is_free' => false, 'quotas' => ['aiCredit' => 5.0]]);
        // Active status but no lemon_squeezy_id => checkout not completed/paid.
        $subscription = $this->subscription(['status' => 'active', 'lemon_squeezy_id' => null], $plan);

        $spec = (new PlanAiKeyResolver())->resolve($this->space($subscription));

        $this->assertFalse($spec->eligible);
    }

    #[Test]
    public function a_paid_plan_with_a_live_subscription_is_eligible(): void
    {
        $plan = new Plan(['is_free' => false, 'quotas' => ['aiCredit' => 5.0]]);
        $subscription = $this->subscription(
            ['status' => 'active', 'lemon_squeezy_id' => 'ls_123'],
            $plan
        );

        $spec = (new PlanAiKeyResolver())->resolve($this->space($subscription));

        $this->assertTrue($spec->eligible);
        $this->assertSame(5.0, $spec->limit);
    }

    #[Test]
    public function a_plan_without_quotas_is_treated_as_unlimited(): void
    {
        $plan = new Plan(['is_free' => false, 'quotas' => null]);
        $subscription = $this->subscription(
            ['status' => 'active', 'lemon_squeezy_id' => 'ls_ent'],
            $plan
        );

        $spec = (new PlanAiKeyResolver())->resolve($this->space($subscription));

        $this->assertTrue($spec->eligible);
        $this->assertTrue($spec->unlimited);
        $this->assertNull($spec->effectiveLimit());
    }

    #[Test]
    public function a_plan_with_no_ai_credit_is_not_eligible(): void
    {
        $plan = new Plan(['is_free' => true, 'quotas' => ['requests' => 1000]]);
        $subscription = $this->subscription(['status' => 'active'], $plan);

        $spec = (new PlanAiKeyResolver())->resolve($this->space($subscription));

        $this->assertFalse($spec->eligible);
    }

    #[Test]
    public function a_cancelled_subscription_is_not_eligible(): void
    {
        $plan = new Plan(['is_free' => false, 'quotas' => ['aiCredit' => 5.0]]);
        $subscription = $this->subscription(
            ['status' => 'cancelled', 'lemon_squeezy_id' => 'ls_123'],
            $plan
        );

        $spec = (new PlanAiKeyResolver())->resolve($this->space($subscription));

        $this->assertFalse($spec->eligible);
    }

    #[Test]
    public function a_subscription_can_override_the_plan_ai_credit(): void
    {
        $plan = new Plan(['is_free' => true, 'quotas' => ['aiCredit' => 1.0]]);
        // Snapshot stored on the subscription wins over the plan definition.
        $subscription = $this->subscription(
            ['status' => 'active', 'quotas' => ['aiCredit' => 3.5]],
            $plan
        );

        $spec = (new PlanAiKeyResolver())->resolve($this->space($subscription));

        $this->assertTrue($spec->eligible);
        $this->assertSame(3.5, $spec->limit);
    }
}
