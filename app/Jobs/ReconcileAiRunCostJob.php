<?php

namespace App\Jobs;

use App\Models\Management\Space;
use App\Models\Space\AiRun;
use App\Services\Ai\Drivers\OpenRouterDriver;
use App\Services\Ai\ModelRegistry;
use App\Support\SpaceContext;

/**
 * Replaces a finished OpenRouter run's token-based cost estimate with the
 * provider's figure. A lookup without a cost releases the job so the queue
 * retries it; once the tries are used up the estimate stays. The sync driver
 * treats a release as a no-op, so a run there keeps its estimate instead of failing.
 */
class ReconcileAiRunCostJob extends QueuedJob
{
    public function __construct(public string $spaceId, public string $runId) {}

    protected function execute(): void
    {
        $space = Space::query()->find($this->spaceId);
        if ($space === null) {
            return;
        }

        $restore = SpaceContext::enter($space);

        try {
            $run = AiRun::query()->find($this->runId);
            $generations = $run?->setup['generations'] ?? [];
            $driver = $generations !== [] ? app(ModelRegistry::class)->getDriverForSpace('openrouter', $space) : null;

            if (! $driver instanceof OpenRouterDriver) {
                return;
            }

            $costs = array_map($driver->generationCost(...), $generations);

            if (\in_array(null, $costs, true)) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }

            $run->update(['cost_usd' => round(array_sum($costs), 6)]);
        } finally {
            $restore();
        }
    }

    protected function handleFailure(\Throwable $e): void {}
}
