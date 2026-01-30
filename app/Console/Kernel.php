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
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}
