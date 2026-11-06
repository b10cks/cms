<?php

namespace App\Jobs;

use App\Models\Management\Space;
use App\Models\Space\AiRun;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Operations\AiOperations;
use App\Services\Ai\Run\ProviderRetry;
use App\Services\Ai\Run\RunBusy;
use App\Services\Ai\Run\RunLease;
use App\Services\Ai\Run\RunRecovery;
use App\Support\SpaceContext;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;

/**
 * Advances a background run for one slice of wall time, then re-dispatches
 * itself while work remains. Holds IDs only: the run lives in the space's
 * database, so the space context is bound before the run is loaded.
 *
 * A finished batch run of an operation hands back to {@see AiOperations},
 * which settles its entries and starts the next batch.
 *
 * A worker that dies does not fail the run: the transcript is checkpointed, so
 * {@see RunRecovery} continues it with a new job. The
 * timeout stays below `ai.runs.lease_seconds`, so a lease outlives its worker.
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
            if (! $run) {
                return;
            }

            // A pause asked for during a backoff stops the run before it spends on the retry.
            if (! $run->isFinished() && ProviderRetry::isPending($run) && ! app(AiOperations::class)->mayContinue($run)) {
                app(AiRunService::class)->cancel($run);
            } elseif (! $run->isFinished()) {
                $advance = app(AiRunService::class)->advance($run, microtime(true) + (int) config('ai.runs.background_slice_seconds'));

                foreach ($advance as $event) {
                    // Background events are stored and broadcast by the service.
                }
            }

            // Settling is idempotent, so a run finished elsewhere (cancelled between slices) settles here too.
            if ($run->operation_id !== null && $run->refresh()->isFinished()) {
                app(AiOperations::class)->runFinished($run);
            }
        } catch (RunBusy) {
            // A live worker owns the run and re-dispatches or finishes it. If it dies, the recovery sweep takes over.
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

            if (! $run) {
                return;
            }

            if ($e instanceof TimeoutExceededException) {
                RunLease::forfeit($run);

                return;
            }

            // The queue redelivered the job of a worker that died long ago; the recovery sweep already owns its run.
            if ($e instanceof MaxAttemptsExceededException || $run->isLeased()) {
                return;
            }

            if (! $run->isFinished()) {
                foreach (app(AiRunService::class)->fail($run, null, 'background_failed', 'The background run failed.', $e) as $event) {
                    // stored by the service
                }
            }

            if ($run->operation_id !== null) {
                app(AiOperations::class)->runFinished($run->refresh());
            }
        } finally {
            $restore();
        }
    }
}
