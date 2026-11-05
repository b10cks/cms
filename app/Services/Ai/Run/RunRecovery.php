<?php

namespace App\Services\Ai\Run;

use App\Enums\AiOperationState;
use App\Enums\AiRunState;
use App\Jobs\RunAgentJob;
use App\Models\Space\AiOperation;
use App\Models\Space\AiRun;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Operations\AiOperations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Picks up work of the current space that lost its worker. Everything goes
 * through the same idempotent steps a worker would take, so sweeping again, or
 * while a worker is mid-step, repeats nothing. It only continues what was
 * already approved: frozen scopes stay frozen, and paused, cancelled, draft and
 * budget-stopped operations are left alone. A run waiting for its
 * {@see ProviderRetry} is picked up only once that is due, which also covers a
 * retry job the queue lost.
 */
class RunRecovery
{
    public function __construct(
        private readonly AiOperations $operations,
        private readonly AiRunService $runs,
    ) {}

    /** @return array{operations: int, runs: int} what was advanced and what was picked up */
    public function sweep(): array
    {
        return ['operations' => $this->advanceOperations(), 'runs' => $this->recoverRuns()];
    }

    /**
     * Settles a finished batch nobody settled, starts the run of a claimed batch
     * and starts the next batch where a worker died before it got that far.
     */
    private function advanceOperations(): int
    {
        $advanced = 0;

        foreach (AiOperation::query()->where('state', AiOperationState::Running->value)->get() as $operation) {
            try {
                $this->operations->advance($operation);
                $advanced++;
            } catch (\Throwable $e) {
                Log::warning('Failed to recover an AI operation', ['operation' => $operation->id, 'error' => $e->getMessage()]);
            }
        }

        return $advanced;
    }

    /**
     * Continue background runs with no live lease and no recent activity. A
     * job can still be waiting in the queue, in which case the second one finds
     * the run busy or finished and does nothing.
     */
    private function recoverRuns(): int
    {
        $recovered = 0;
        $abandoned = AiRun::query()
            ->where('state', AiRunState::Running->value)
            ->where('updated_at', '<=', now()->subSeconds((int) config('ai.runs.recovery_grace_seconds')))
            ->where(fn (Builder $query) => $query->where('mode', AiRun::MODE_BACKGROUND)->orWhereNotNull('operation_id'))
            ->where(fn (Builder $query) => $query->whereNull('lease_owner')->orWhere('lease_expires_at', '<=', now()))
            ->get(['id', 'space_id', 'operation_id', 'cancel_requested', 'setup']);

        foreach ($abandoned as $run) {
            try {
                $recovered += $this->recover($run) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('Failed to recover an AI run', ['run' => $run->id, 'error' => $e->getMessage()]);
            }
        }

        return $recovered;
    }

    /** @return bool false when the run still has to wait for its retry */
    private function recover(AiRun $run): bool
    {
        if ($run->cancel_requested || ! $this->operations->mayContinue($run)) {
            $full = AiRun::query()->findOrFail($run->id);
            $this->runs->cancel($full);
            if ($full->refresh()->isFinished()) {
                $this->operations->runFinished($full);
            }

            return true;
        }

        if (ProviderRetry::isWaiting($run)) {
            return false;
        }

        // Counts as activity, so a job waiting in a long queue is not joined by another every sweep.
        AiRun::query()->whereKey($run->id)->touch();
        RunAgentJob::dispatch($run->space_id, $run->id);

        return true;
    }
}
