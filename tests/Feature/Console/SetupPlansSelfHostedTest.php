<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SetupPlansCommand;
use App\Models\Management\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SetupPlansCommand::class)]
class SetupPlansSelfHostedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_a_single_unlimited_free_plan(): void
    {
        $this->artisan('plans:setup', ['--self-hosted' => true])->assertSuccessful();

        $this->assertSame(1, Plan::count());

        $plan = Plan::sole();
        $this->assertTrue($plan->is_free);
        $this->assertTrue($plan->is_active);
        $this->assertNull($plan->quotas);
        $this->assertNull($plan->contact_url);
        $this->assertSame('0.00', $plan->price);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $this->artisan('plans:setup', ['--self-hosted' => true])->assertSuccessful();
        $this->artisan('plans:setup', ['--self-hosted' => true])->assertSuccessful();

        $this->assertSame(1, Plan::count());
    }

    #[Test]
    public function backfill_free_succeeds_against_the_self_hosted_plan(): void
    {
        $this->artisan('plans:setup', ['--self-hosted' => true])->assertSuccessful();

        $this->artisan('subscriptions:backfill-free')->assertSuccessful();
    }
}
