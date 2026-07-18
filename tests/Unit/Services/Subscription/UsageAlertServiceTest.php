<?php

namespace Tests\Unit\Services\Subscription;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\SpaceUsageAlert;
use App\Models\Management\Subscription;
use App\Models\User;
use App\Notifications\Space\UsageThresholdNotification;
use App\Services\Space\Dto\UsageMetricDto;
use App\Services\Space\SpaceUsageService;
use App\Services\Subscription\UsageAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsageAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Space $space;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Notification::fake();

        $this->space = Space::factory()->create();
        $this->owner = User::factory()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->space->load('users');

        $plan = Plan::create([
            'name' => ['default' => 'Pro'],
            'price' => 10,
            'period' => 'month',
            'quotas' => ['requests' => 1000, 'traffic' => 1000, 'storage' => 1000, 'aiCredit' => 5.0],
            'is_free' => false,
            'is_active' => true,
        ]);

        Subscription::factory()->create([
            'space_id' => $this->space->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'quotas' => null,
        ]);
    }

    /**
     * Stub the usage snapshot: all metrics healthy except the given overrides.
     *
     * @param  array<string, UsageMetricDto>  $overrides
     */
    private function fakeUsage(array $overrides = []): void
    {
        $usage = array_merge([
            'storage' => new UsageMetricDto('storage', 'bytes', 100, 1000),
            'traffic' => new UsageMetricDto('traffic', 'bytes', 100, 1000),
            'downloads' => new UsageMetricDto('downloads', 'bytes', 0, null),
            'requests' => new UsageMetricDto('requests', 'count', 100, 1000),
            'ai' => new UsageMetricDto('ai', 'usd', 0.5, 5.0),
            'period' => ['start' => now()->startOfMonth()->toIso8601String(), 'end' => now()->toIso8601String(), 'resets_at' => now()->addMonth()->toIso8601String()],
        ], $overrides);

        $this->mock(SpaceUsageService::class, function (MockInterface $mock) use ($usage): void {
            $mock->shouldReceive('forSpace')->andReturn($usage);
        });
    }

    private function service(): UsageAlertService
    {
        return app(UsageAlertService::class);
    }

    #[Test]
    public function no_alert_is_sent_below_the_first_threshold(): void
    {
        $this->fakeUsage();

        $this->assertSame(0, $this->service()->check($this->space));
        Notification::assertNothingSent();
    }

    #[Test]
    public function crossing_eighty_percent_notifies_billing_viewers_once(): void
    {
        $this->fakeUsage(['traffic' => new UsageMetricDto('traffic', 'bytes', 850, 1000)]);

        $this->assertSame(1, $this->service()->check($this->space));
        // Second run in the same window is silent.
        $this->assertSame(0, $this->service()->check($this->space));

        Notification::assertSentTo(
            $this->owner,
            UsageThresholdNotification::class,
            fn ($notification) => $notification->toArray($this->owner)['threshold'] === 80
                && $notification->toArray($this->owner)['metric'] === 'traffic'
        );
        Notification::assertCount(1);
    }

    #[Test]
    public function jumping_past_both_thresholds_sends_only_the_exceeded_alert(): void
    {
        $this->fakeUsage(['requests' => new UsageMetricDto('requests', 'count', 1500, 1000)]);

        $this->assertSame(1, $this->service()->check($this->space));

        Notification::assertSentTo(
            $this->owner,
            UsageThresholdNotification::class,
            fn ($notification) => $notification->toArray($this->owner)['threshold'] === 100
        );
        Notification::assertCount(1);

        // Both thresholds are recorded so neither fires later.
        $this->assertSame(2, SpaceUsageAlert::where('space_id', $this->space->id)->count());
        $this->assertSame(0, $this->service()->check($this->space));
    }

    #[Test]
    public function unlimited_and_unavailable_metrics_never_alert(): void
    {
        $this->fakeUsage([
            'traffic' => new UsageMetricDto('traffic', 'bytes', 999999, null),
            'ai' => new UsageMetricDto('ai', 'usd', 99.0, 5.0, available: false),
        ]);

        $this->assertSame(0, $this->service()->check($this->space));
        Notification::assertNothingSent();
    }

    #[Test]
    public function users_without_billing_visibility_are_not_notified(): void
    {
        $bystander = User::factory()->create();
        $this->assignSpaceRole($this->space, $bystander, 'editor');
        $this->space->load('users');

        $this->fakeUsage(['storage' => new UsageMetricDto('storage', 'bytes', 999, 1000)]);

        $this->service()->check($this->space);

        Notification::assertSentTo($this->owner, UsageThresholdNotification::class);
        Notification::assertNotSentTo($bystander, UsageThresholdNotification::class);
    }

    #[Test]
    public function a_space_without_an_active_subscription_is_skipped(): void
    {
        Subscription::query()->update(['status' => 'expired']);
        $this->space->unsetRelation('subscriptions');

        $this->fakeUsage(['traffic' => new UsageMetricDto('traffic', 'bytes', 5000, 1000)]);

        $this->assertSame(0, $this->service()->check($this->space));
        Notification::assertNothingSent();
    }
}
