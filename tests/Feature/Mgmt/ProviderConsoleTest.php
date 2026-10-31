<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Models\Management\SubscriptionPeriod;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenants_show_monthly_revenue_and_billing_flags(): void
    {
        Bus::fake();
        $plan = Plan::query()->forceCreate(['name' => ['default' => 'Pro'], 'price' => '20.00', 'yearly_price' => '240.00', 'period' => 'month']);
        $team = Team::factory()->create();
        $monthly = Space::factory()->create(['team_id' => $team->id]);
        $yearly = Space::factory()->create(['team_id' => $team->id]);
        $failing = Space::factory()->create(['team_id' => $team->id]);

        Subscription::factory()->create(['space_id' => $monthly->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'month']);
        Subscription::factory()->create(['space_id' => $yearly->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'year']);
        Subscription::factory()->create(['space_id' => $failing->id, 'plan_id' => $plan->id, 'status' => 'unpaid', 'billing_interval' => 'month']);

        $tenant = collect($this->actingAs(User::factory()->create(['is_root' => true]))
            ->getJson(route('mgmt.provider.tenants'))
            ->assertOk()
            ->json('data'))->firstWhere('id', $team->id);

        $this->assertEquals(40.0, $tenant['mrr']);
        $this->assertSame(3, $tenant['spaces_count']);
        $this->assertContains('payment_failed', $tenant['flags']);
        $this->assertContains('no_owner', $tenant['flags']);
    }

    #[Test]
    public function usage_rolls_child_teams_up_into_their_top_level_team(): void
    {
        Bus::fake();
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);
        $space = Space::factory()->create(['team_id' => $child->id]);

        SubscriptionPeriod::query()->forceCreate([
            'space_id' => $space->id,
            'plan_name' => 'Pro',
            'quotas' => ['storage' => 1000, 'traffic' => 1000],
            'price' => '30.00',
            'billing_period' => 'month',
            'status' => 'active',
            'started_at' => now()->startOfMonth(),
            'storage_bytes' => 900,
            'ai_spend_usd' => '1.50',
        ]);

        $rows = $this->actingAs(User::factory()->create(['is_root' => true]))
            ->getJson(route('mgmt.provider.usage', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($parent->id, $rows[0]['team']['id']);
        $this->assertEquals(30.0, $rows[0]['revenue']);
        $this->assertEquals(1.5, $rows[0]['ai_spend']);
        $this->assertEquals(0.9, $rows[0]['storage']['share']);
        $this->assertContains('storage', $rows[0]['flags']);
    }
}
