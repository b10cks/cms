<?php

namespace Tests\Feature\Mgmt;

use App\Actions\Subscription\SyncSubscriptionFromLemonSqueezy;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Models\User;
use App\Services\LemonSqueezy\LemonSqueezyService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => ['en' => 'Pro', 'default' => 'Pro'],
            'price' => '29.00',
            'yearly_price' => '290.00',
            'period' => 'month',
            'quotas' => ['traffic' => 1000],
            'ls_product_id' => '9001',
            'ls_variant_id' => '1001',
            'ls_variant_id_yearly' => '2001',
            'is_free' => false,
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    private function sync(): SyncSubscriptionFromLemonSqueezy
    {
        $this->partialMock(LemonSqueezyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getCustomerPortalUrl')->andReturnNull();
        });

        return app(SyncSubscriptionFromLemonSqueezy::class);
    }

    private function lsPayload(Space $space, Subscription $subscription, string $variantId, string $status = 'active'): array
    {
        return [
            'id' => '777001',
            'attributes' => [
                'customer_id' => 555,
                'product_id' => 9001,
                'variant_id' => (int) $variantId,
                'product_name' => 'b10cks',
                'variant_name' => 'Pro',
                'status' => $status,
                'first_subscription_item' => ['quantity' => 1],
                'renews_at' => now()->addMonth()->toIso8601String(),
                'ends_at' => null,
                'custom_data' => [
                    'space_id' => $space->id,
                    'subscription_id' => $subscription->id,
                ],
            ],
        ];
    }

    #[Test]
    public function a_yearly_variant_resolves_the_plan_and_marks_the_interval(): void
    {
        $space = Space::factory()->create();
        $plan = $this->plan();

        $pending = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'name' => 'Pro',
            'status' => 'pending',
            'variant_id' => '2001',
            'product_id' => '9001',
            'quantity' => 1,
            'billing_interval' => 'year',
        ]);

        $synced = $this->sync()->fromWebhook($this->lsPayload($space, $pending, '2001'));

        $this->assertSame($plan->id, $synced->plan_id);
        $this->assertSame('year', $synced->billing_interval);
        $this->assertSame('active', $synced->status);
    }

    #[Test]
    public function a_custom_quota_override_survives_a_same_plan_sync(): void
    {
        $space = Space::factory()->create();
        $plan = $this->plan();

        $subscription = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'name' => 'Pro',
            'status' => 'active',
            'lemon_squeezy_id' => '777001',
            'variant_id' => '1001',
            'product_id' => '9001',
            'quantity' => 1,
            'quotas' => ['traffic' => 999999], // subsidized deal
        ]);

        $synced = $this->sync()->fromWebhook($this->lsPayload($space, $subscription, '1001'));

        $this->assertSame(['traffic' => 999999], $synced->quotas);
        $this->assertSame(['traffic' => 999999], $synced->effectiveQuotas());
    }

    #[Test]
    public function a_quota_override_is_dropped_when_the_plan_changes(): void
    {
        $space = Space::factory()->create();
        $this->plan();
        $other = $this->plan([
            'name' => ['en' => 'Scale', 'default' => 'Scale'],
            'ls_variant_id' => '3001',
            'ls_variant_id_yearly' => null,
            'quotas' => ['traffic' => 5000],
            'sort_order' => 2,
        ]);

        $subscription = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $this->planIdByName('Pro'),
            'name' => 'Pro',
            'status' => 'active',
            'lemon_squeezy_id' => '777001',
            'variant_id' => '1001',
            'product_id' => '9001',
            'quantity' => 1,
            'quotas' => ['traffic' => 999999],
        ]);

        $synced = $this->sync()->fromWebhook($this->lsPayload($space, $subscription, '3001'));

        $this->assertSame($other->id, $synced->plan_id);
        $this->assertNull($synced->quotas);
        $this->assertSame(['traffic' => 5000], $synced->effectiveQuotas());
    }

    #[Test]
    public function the_public_plan_list_hides_custom_plans(): void
    {
        $this->plan();
        $this->plan([
            'name' => ['en' => 'Agency Deal', 'default' => 'Agency Deal'],
            'is_public' => false,
            'ls_variant_id' => '4001',
            'ls_variant_id_yearly' => null,
            'sort_order' => 3,
        ]);

        $response = $this->getJson('/mgmt/v1/plans');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Pro', $names);
        $this->assertNotContains('Agency Deal', $names);
    }

    #[Test]
    public function the_space_plan_list_includes_granted_custom_plans(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        $this->plan();
        $custom = $this->plan([
            'name' => ['en' => 'Agency Deal', 'default' => 'Agency Deal'],
            'is_public' => false,
            'ls_variant_id' => '4001',
            'ls_variant_id_yearly' => null,
            'sort_order' => 3,
        ]);
        $ungranted = $this->plan([
            'name' => ['en' => 'Other Deal', 'default' => 'Other Deal'],
            'is_public' => false,
            'ls_variant_id' => '5001',
            'ls_variant_id_yearly' => null,
            'sort_order' => 4,
        ]);
        $custom->spaces()->attach($space->id);

        $response = $this->actingAs($user)->getJson("/mgmt/v1/spaces/{$space->id}/plans");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Pro', $names);
        $this->assertContains('Agency Deal', $names);
        $this->assertNotContains('Other Deal', $names);
    }

    #[Test]
    public function checkout_rejects_a_custom_plan_the_space_was_not_granted(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        $custom = $this->plan([
            'name' => ['en' => 'Agency Deal', 'default' => 'Agency Deal'],
            'is_public' => false,
            'sort_order' => 3,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/mgmt/v1/spaces/{$space->id}/subscriptions/checkout",
            ['plan_id' => $custom->id],
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function checkout_rejects_yearly_billing_on_a_monthly_only_plan(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        $plan = $this->plan(['yearly_price' => null, 'ls_variant_id_yearly' => null]);

        $response = $this->actingAs($user)->postJson(
            "/mgmt/v1/spaces/{$space->id}/subscriptions/checkout",
            ['plan_id' => $plan->id, 'interval' => 'year'],
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function space_creation_rejects_a_non_public_plan(): void
    {
        $user = User::factory()->create();
        $custom = $this->plan([
            'name' => ['en' => 'Agency Deal', 'default' => 'Agency Deal'],
            'is_public' => false,
        ]);

        $response = $this->actingAs($user)->postJson(route('mgmt.spaces.store'), [
            'name' => 'My Space',
            'slug' => 'my-space',
            'plan_id' => $custom->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->assertDatabaseMissing('spaces', ['slug' => 'my-space']);
    }

    #[Test]
    public function space_creation_rejects_a_yearly_interval_without_a_yearly_variant(): void
    {
        config(['services.lemonsqueezy.api_key' => 'key', 'services.lemonsqueezy.store_id' => '1']);

        $user = User::factory()->create();
        // yearly_price set (supportsYearly) but the yearly LS variant is missing —
        // must NOT silently create an unpaid Active subscription.
        $plan = $this->plan(['ls_variant_id_yearly' => null]);

        $response = $this->actingAs($user)->postJson(route('mgmt.spaces.store'), [
            'name' => 'My Space',
            'slug' => 'my-space',
            'plan_id' => $plan->id,
            'billing_interval' => 'year',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->assertDatabaseMissing('spaces', ['slug' => 'my-space']);
    }

    #[Test]
    public function switching_to_free_during_cancellation_grace_keeps_the_paid_grace(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        $paid = $this->plan();
        $free = $this->plan([
            'name' => ['en' => 'Free', 'default' => 'Free'],
            'price' => '0.00',
            'yearly_price' => null,
            'ls_variant_id' => null,
            'ls_variant_id_yearly' => null,
            'is_free' => true,
            'sort_order' => 0,
        ]);

        Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $paid->id,
            'name' => 'Pro',
            'status' => 'cancelled',
            'lemon_squeezy_id' => '777001',
            'variant_id' => '1001',
            'product_id' => '9001',
            'quantity' => 1,
            'ends_at' => now()->addWeeks(2),
        ]);

        $response = $this->actingAs($user)->postJson(
            "/mgmt/v1/spaces/{$space->id}/subscriptions/checkout",
            ['plan_id' => $free->id],
        );

        $response->assertOk()->assertJsonPath('scheduled', true);
        // No free subscription yet — reconciliation enrolls it once grace lapses.
        $this->assertNull($space->subscriptions()->where('plan_id', $free->id)->first());
    }

    #[Test]
    public function cancelling_a_terminal_subscription_survives_an_ls_client_error(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        $plan = $this->plan();
        $subscription = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'name' => 'Pro',
            'status' => 'unpaid',
            'lemon_squeezy_id' => '777001',
            'variant_id' => '1001',
            'product_id' => '9001',
            'quantity' => 1,
            'renews_at' => now()->addMonth(),
        ]);

        $this->partialMock(LemonSqueezyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('cancelSubscription')->andThrow(new ClientException(
                'Unprocessable',
                new Psr7Request('DELETE', 'subscriptions/777001'),
                new Psr7Response(422),
            ));
        });

        $response = $this->actingAs($user)->postJson("/mgmt/v1/spaces/{$space->id}/subscriptions/cancel");

        $response->assertOk();
        $subscription->refresh();
        $this->assertSame('cancelled', $subscription->status);
        // Was not entitlement-granting (unpaid) — no grace window is granted.
        $this->assertTrue($subscription->ends_at->lte(now()));
    }

    #[Test]
    public function a_plan_change_does_not_mask_a_past_due_subscription_as_active(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        $this->plan();
        $other = $this->plan([
            'name' => ['en' => 'Scale', 'default' => 'Scale'],
            'ls_variant_id' => '3001',
            'ls_variant_id_yearly' => null,
            'yearly_price' => null,
            'sort_order' => 2,
        ]);

        $subscription = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $this->planIdByName('Pro'),
            'name' => 'Pro',
            'status' => 'past_due',
            'lemon_squeezy_id' => '777001',
            'variant_id' => '1001',
            'product_id' => '9001',
            'quantity' => 1,
        ]);

        $lsResponse = $this->lsPayload($space, $subscription, '3001', status: 'past_due');
        $this->partialMock(LemonSqueezyService::class, function (MockInterface $mock) use ($lsResponse): void {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('changeSubscriptionVariant')->andReturn($lsResponse);
            $mock->shouldReceive('getCustomerPortalUrl')->andReturnNull();
        });

        $response = $this->actingAs($user)->postJson(
            "/mgmt/v1/spaces/{$space->id}/subscriptions/checkout",
            ['plan_id' => $other->id],
        );

        $response->assertOk()->assertJsonPath('upgraded', true);
        $subscription->refresh();
        $this->assertSame($other->id, $subscription->plan_id);
        $this->assertSame('past_due', $subscription->status);
    }

    #[Test]
    public function an_abandoned_pending_checkout_can_be_discarded(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        $plan = $this->plan();
        $pending = Subscription::factory()->create([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'lemon_squeezy_id' => null,
        ]);

        $this->actingAs($user)
            ->deleteJson("/mgmt/v1/spaces/{$space->id}/subscriptions/pending")
            ->assertStatus(204);

        $this->assertSame('expired', $pending->fresh()->status);
    }

    #[Test]
    public function discarding_leaves_a_pending_checkout_known_to_lemonsqueezy_alone(): void
    {
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $this->assignSpaceRole($space, $user, 'owner');

        // Mid-webhook: LS already knows this subscription — it resolves on its own.
        $pending = Subscription::factory()->create([
            'space_id' => $space->id,
            'plan_id' => $this->plan()->id,
            'status' => 'pending',
            'lemon_squeezy_id' => '888001',
        ]);

        $this->actingAs($user)
            ->deleteJson("/mgmt/v1/spaces/{$space->id}/subscriptions/pending")
            ->assertStatus(204);

        $this->assertSame('pending', $pending->fresh()->status);
    }

    private function planIdByName(string $name): string
    {
        return Plan::whereJsonContains('name->default', $name)->firstOrFail()->id;
    }
}
