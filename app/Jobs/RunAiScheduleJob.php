<?php

namespace App\Jobs;

use App\Models\Management\AiSchedule;
use App\Services\Ai\Schedules\ScheduleRunner;
use Illuminate\Support\Facades\Log;

/** Starts one due schedule's background run. Holds the schedule ID only. */
class RunAiScheduleJob extends QueuedJob
{
    public $tries = 1;

    public function __construct(public string $scheduleId) {}

    protected function execute(): void
    {
        $schedule = AiSchedule::query()->with(['space', 'creator'])->find($this->scheduleId);

        if ($schedule?->enabled) {
            app(ScheduleRunner::class)->run($schedule);
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::warning('Scheduled AI run failed to start', ['schedule' => $this->scheduleId, 'error' => $e->getMessage()]);
    }
}
