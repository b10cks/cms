<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\LemonSqueezyWebhookController;
use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(LemonSqueezyWebhookController::class)]
class LemonSqueezyWebhookControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.lemonsqueezy.webhook_secret', 'test-webhook-secret');
    }

    #[Test]
    public function it_rejects_requests_with_an_invalid_signature(): void
    {
        $payload = [
            'meta' => ['event_name' => 'subscription_updated'],
            'data' => ['id' => '1949171', 'type' => 'subscriptions', 'attributes' => []],
        ];

        $response = $this->call(
            'POST',
            route('webhooks.lemonsqueezy'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => 'invalid-signature',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(401);
    }

    #[Test]
    public function it_syncs_subscription_updates_using_meta_event_name_and_custom_data(): void
    {
        $space = Space::factory()->withLive()->create();
        $plan = $this->createPlan('Scale', '1374497', '872809');

        $pending = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'name' => 'Pending Scale',
            'status' => 'pending',
            'variant_id' => $plan->ls_variant_id,
            'product_id' => $plan->ls_product_id,
            'quantity' => 1,
            'quotas' => $plan->quotas,
        ]);

        $payload = $this->subscriptionPayload(
            event: 'subscription_updated',
            subscriptionId: '1949171',
            spaceId: $space->id,
            localSubscriptionId: $pending->id,
            variantId: $plan->ls_variant_id,
            productId: $plan->ls_product_id,
            status: 'active',
            quantity: 3,
        );

        $response = $this->postSignedWebhook($payload);

        $response->assertOk();

        $pending->refresh();

        $this->assertSame('1949171', $pending->lemon_squeezy_id);
        $this->assertSame('active', $pending->status);
        $this->assertSame($plan->id, $pending->plan_id);
        $this->assertSame('7991502', $pending->ls_customer_id);
        $this->assertSame(3, $pending->quantity);
        $this->assertSame(
            'https://b10cks.lemonsqueezy.com/billing?expires=1773079916&test_mode=1&user=950711&signature=portal',
            $pending->billing_portal_url,
        );
    }

    #[Test]
    public function it_updates_the_local_plan_when_lemonsqueezy_reports_a_plan_change(): void
    {
        $space = Space::factory()->withLive()->create();
        $starter = $this->createPlan('Starter', '1374000', '872800');
        $scale = $this->createPlan('Scale', '1374497', '872809');

        $subscription = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $starter->id,
            'name' => 'Starter',
            'status' => 'active',
            'lemon_squeezy_id' => '1949171',
            'variant_id' => $starter->ls_variant_id,
            'product_id' => $starter->ls_product_id,
            'quantity' => 1,
            'quotas' => $starter->quotas,
        ]);

        $payload = $this->subscriptionPayload(
            event: 'subscription_plan_changed',
            subscriptionId: '1949171',
            spaceId: $space->id,
            localSubscriptionId: $subscription->id,
            variantId: $scale->ls_variant_id,
            productId: $scale->ls_product_id,
            status: 'active',
        );

        $response = $this->postSignedWebhook($payload);

        $response->assertOk();

        $subscription->refresh();

        $this->assertSame($scale->id, $subscription->plan_id);
        $this->assertSame($scale->ls_variant_id, $subscription->variant_id);
        $this->assertSame($scale->ls_product_id, $subscription->product_id);
    }

    #[Test]
    public function it_fetches_the_subscription_for_invoice_payment_events(): void
    {
        $space = Space::factory()->withLive()->create();
        $plan = $this->createPlan('Scale', '1374497', '872809');

        $subscription = Subscription::create([
            'space_id' => $space->id,
            'plan_id' => $plan->id,
            'name' => 'Scale',
            'status' => 'active',
            'lemon_squeezy_id' => '1949171',
            'variant_id' => $plan->ls_variant_id,
            'product_id' => $plan->ls_product_id,
            'quantity' => 1,
            'quotas' => $plan->quotas,
        ]);

        $fullSubscription = $this->subscriptionPayload(
            event: 'subscription_payment_failed',
            subscriptionId: '1949171',
            spaceId: $space->id,
            localSubscriptionId: $subscription->id,
            variantId: $plan->ls_variant_id,
            productId: $plan->ls_product_id,
            status: 'past_due',
        );

        $this->partialMock(LemonSqueezyService::class, function (MockInterface $mock) use ($fullSubscription): void {
            $mock->shouldReceive('getSubscription')
                ->once()
                ->with('1949171')
                ->andReturn($fullSubscription['data']);
        });

        $payload = [
            'meta' => ['event_name' => 'subscription_payment_failed'],
            'data' => [
                'type' => 'subscription-invoices',
                'id' => 'in_123',
                'attributes' => [
                    'subscription_id' => 1949171,
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);

        $response->assertOk();

        $subscription->refresh();

        $this->assertSame('past_due', $subscription->status);
    }

    private function postSignedWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, (string) config('services.lemonsqueezy.webhook_secret'));

        return $this->call(
            'POST',
            route('webhooks.lemonsqueezy'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            $body,
        );
    }

    private function createPlan(string $name, string $variantId, string $productId): Plan
    {
        return Plan::create([
            'name' => ['en' => $name],
            'description' => ['en' => "{$name} plan"],
            'features' => ['en' => ["{$name} feature"]],
            'price' => '29.00',
            'period' => 'monthly',
            'quotas' => ['entries' => 1000],
            'ls_variant_id' => $variantId,
            'ls_product_id' => $productId,
            'is_free' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function subscriptionPayload(
        string $event,
        string $subscriptionId,
        string $spaceId,
        string $localSubscriptionId,
        string $variantId,
        string $productId,
        string $status,
        int $quantity = 1,
    ): array {
        return [
            'meta' => [
                'event_name' => $event,
                'custom_data' => [
                    'plan_id' => 'plan-from-checkout',
                    'space_id' => $spaceId,
                    'subscription_id' => $localSubscriptionId,
                ],
                'webhook_id' => 'a1423b1c-4ca7-46d5-8f86-2444357c02cc',
            ],
            'data' => [
                'type' => 'subscriptions',
                'id' => $subscriptionId,
                'attributes' => [
                    'store_id' => 168091,
                    'customer_id' => 7991502,
                    'product_id' => (int) $productId,
                    'variant_id' => (int) $variantId,
                    'product_name' => 'b10cks Subscription',
                    'variant_name' => 'Scale',
                    'status' => $status,
                    'trial_ends_at' => null,
                    'first_subscription_item' => [
                        'quantity' => $quantity,
                    ],
                    'urls' => [
                        'customer_portal' => 'https://b10cks.lemonsqueezy.com/billing?expires=1773079916&test_mode=1&user=950711&signature=portal',
                    ],
                    'renews_at' => '2026-04-09T12:11:18.000000Z',
                    'ends_at' => null,
                    'created_at' => '2026-03-09T12:11:20.000000Z',
                    'updated_at' => '2026-03-09T12:11:26.000000Z',
                    'custom_data' => [
                        'space_id' => $spaceId,
                        'subscription_id' => $localSubscriptionId,
                    ],
                ],
            ],
        ];
    }
}
