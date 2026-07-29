<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class QueuedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    // Exponential retry spacing; ignored by jobs that set $tries = 1. Without
    // this, all retries fire back-to-back against a resource that just failed.
    public $backoff = [10, 60, 300];

    public function handle(): void
    {
        // Defense-in-depth tenant isolation for long-lived workers: snapshot any
        // ambient `currentSpace` binding and restore it afterwards, so a job that
        // sets a per-space DB context can never leak it into the next job (which
        // might otherwise read/write the wrong space's database).
        $hadSpace = app()->bound('currentSpace');
        $priorSpace = $hadSpace ? app('currentSpace') : null;

        try {
            // Let any Throwable propagate so the queue can retry. Failure
            // handling lives in failed() below, which the framework invokes
            // exactly once — after the final attempt — rather than every retry.
            $this->execute();
            $this->handleCompletion();
        } finally {
            if ($hadSpace) {
                app()->offsetSet('currentSpace', $priorSpace);
            } else {
                app()->offsetUnset('currentSpace');
            }
        }
    }

    /**
     * Called by the queue when the job ultimately fails (after exhausting
     * retries, or immediately for a fatal Error). Runs once, and — unlike the
     * previous \Exception-only catch — also fires for Error/TypeError.
     */
    public function failed(\Throwable $e): void
    {
        $this->handleFailure($e);
    }

    abstract protected function execute(): void;

    abstract protected function handleFailure(\Throwable $e): void;

    protected function handleCompletion(): void
    {

    }
}
