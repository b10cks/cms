<?php

namespace App\Jobs;

use App\Models\Management\AiRun;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Run\RunBusy;
use App\Support\SpaceContext;

/**
 * Advances a background run for one slice of wall time, then re-dispatches
 * itself while work remains. Holds the run ID only; the space context is
 * bound from the run.
 */
class RunAgentJob extends QueuedJob
{
    public $tries = 1;

    public $timeout = 900;

    public function __construct(public string $runId)
    {
        $this->onQueue('heavy');
    }

    protected function execute(): void
    {
        $run = AiRun::query()->with('space')->find($this->runId);

        if (! $run || $run->isFinished() || ! $run->space) {
            return;
        }

        $service = app(AiRunService::class);
        $restore = SpaceContext::enter($run->space);

        try {
            $advance = $service->advance($run, microtime(true) + (int) config('ai.runs.background_slice_seconds'));

            foreach ($advance as $event) {
                // Background events are stored and broadcast by the service.
            }
        } catch (RunBusy) {
            // The inline request still holds the lock; try again shortly.
            self::dispatch($this->runId)->delay(now()->addSeconds(5));
        } finally {
            $restore();
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        $run = AiRun::query()->find($this->runId);

        if (! $run || $run->isFinished()) {
            return;
        }

        foreach (app(AiRunService::class)->fail($run, null, 'background_failed', 'The background run failed.', $e) as $event) {
            // stored by the service
        }
    }
}
