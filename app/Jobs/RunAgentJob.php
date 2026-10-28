<?php

namespace App\Jobs;

use App\Models\Management\Space;
use App\Models\Space\AiRun;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Run\RunBusy;
use App\Support\SpaceContext;

/**
 * Advances a background run for one slice of wall time, then re-dispatches
 * itself while work remains. Holds IDs only: the run lives in the space's
 * database, so the space context is bound before the run is loaded.
 */
class RunAgentJob extends QueuedJob
{
    public $tries = 1;

    public $timeout = 900;

    public function __construct(public string $spaceId, public string $runId)
    {
        $this->onQueue('heavy');
    }

    protected function execute(): void
    {
        $space = Space::query()->find($this->spaceId);
        if (! $space) {
            return;
        }

        $restore = SpaceContext::enter($space);

        try {
            $run = AiRun::query()->find($this->runId);
            if (! $run || $run->isFinished()) {
                return;
            }

            $advance = app(AiRunService::class)->advance($run, microtime(true) + (int) config('ai.runs.background_slice_seconds'));

            foreach ($advance as $event) {
                // Background events are stored and broadcast by the service.
            }
        } catch (RunBusy) {
            // The inline request still holds the lock; try again shortly.
            self::dispatch($this->spaceId, $this->runId)->delay(now()->addSeconds(5));
        } finally {
            $restore();
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        $space = Space::query()->find($this->spaceId);
        if (! $space) {
            return;
        }

        $restore = SpaceContext::enter($space);

        try {
            $run = AiRun::query()->find($this->runId);

            if (! $run || $run->isFinished()) {
                return;
            }

            foreach (app(AiRunService::class)->fail($run, null, 'background_failed', 'The background run failed.', $e) as $event) {
                // stored by the service
            }
        } finally {
            $restore();
        }
    }
}
