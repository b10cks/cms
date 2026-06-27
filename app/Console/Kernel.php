<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->useCache('database');

        $schedule->command('cloudfront:ingest-logs')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('queue:requeue-orphaned-publishing')
            ->dailyAt('01:00')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('backup:cleanup-expired')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('subscriptions:sync-lemonsqueezy')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();

        // Reconcile OpenRouter keys with each space's plan/subscription and
        // reissue them at the start of every reset period to refresh budgets.
        $schedule->command('ai:reissue-keys')
            ->dailyAt('02:30')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();

        // Close billing periods that rolled over via the hourly LS sync and
        // open any missing ones (safety net for the event-driven reconcile).
        $schedule->command('subscriptions:reconcile-periods')
            ->dailyAt('03:00')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('automations:run-scheduled')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
