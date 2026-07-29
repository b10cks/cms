<?php

namespace Tests\Feature\Console;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

class ScheduleEditionGatingTest extends TestCase
{
    private const SAAS_COMMANDS = [
        'cloudfront:ingest-logs',
        'subscriptions:sync-lemonsqueezy',
        'usage:check-quotas',
        'ai:reissue-keys',
        'subscriptions:reconcile-periods',
    ];

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = new Schedule;

        (new ReflectionMethod($kernel, 'schedule'))->invoke($kernel, $schedule);

        return collect($schedule->events())
            ->map(fn ($event) => (string) $event->command)
            ->all();
    }

    public function test_saas_schedule_contains_billing_and_metering_jobs(): void
    {
        config(['edition.edition' => 'saas']);

        $commands = implode("\n", $this->scheduledCommands());

        foreach (self::SAAS_COMMANDS as $command) {
            $this->assertStringContainsString($command, $commands);
        }
    }

    public function test_self_hosted_schedule_omits_saas_jobs_but_keeps_neutral_ones(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $commands = implode("\n", $this->scheduledCommands());

        foreach (self::SAAS_COMMANDS as $command) {
            $this->assertStringNotContainsString($command, $commands);
        }

        foreach (['automations:run-scheduled', 'backup:cleanup-expired', 'usage:compact-hourly'] as $command) {
            $this->assertStringContainsString($command, $commands);
        }
    }
}
