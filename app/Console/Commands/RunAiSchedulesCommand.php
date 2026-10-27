<?php

namespace App\Console\Commands;

use App\Jobs\RunAiScheduleJob;
use App\Models\Management\AiSchedule;
use App\Services\Ai\Schedules\ScheduleRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RunAiSchedulesCommand extends Command
{
    protected $signature = 'ai:run-schedules';

    protected $description = 'Dispatch the AI schedules that are due';

    public function handle(): int
    {
        $now = now();
        $minute = $now->copy()->startOfMinute();
        $dispatched = 0;

        AiSchedule::query()->where('enabled', true)->each(function (AiSchedule $schedule) use ($now, $minute, &$dispatched) {
            if (! ScheduleRunner::isDue($schedule, $now)) {
                return;
            }

            // Claim the minute, so an overlapping invocation cannot start it twice.
            $claimed = AiSchedule::query()
                ->whereKey($schedule->id)
                ->where(fn (Builder $query) => $query->whereNull('last_run_at')->orWhere('last_run_at', '<', $minute))
                ->update(['last_run_at' => $now]);

            if ($claimed === 1) {
                RunAiScheduleJob::dispatch($schedule->id);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} AI schedules.");

        return self::SUCCESS;
    }
}
