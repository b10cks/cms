<?php

namespace App\Jobs;

use App\Models\Management\AiRun;
use App\Models\Management\Space;
use App\Services\Ai\Drivers\OpenRouterDriver;
use App\Services\Ai\ModelRegistry;
use RuntimeException;

/**
 * Replaces a finished OpenRouter run's token-based cost estimate with the
 * provider's figure. Any failed lookup throws so the queue retries; once the
 * retries are used up the estimate stays.
 */
class ReconcileAiRunCostJob extends QueuedJob
{
    public function __construct(public string $runId) {}

    protected function execute(): void
    {
        $run = AiRun::query()->find($this->runId);
        $generations = $run?->setup['generations'] ?? [];
        $space = $run ? Space::query()->find($run->space_id) : null;

        if ($generations === [] || $space === null) {
            return;
        }

        $driver = app(ModelRegistry::class)->getDriverForSpace('openrouter', $space);

        if (! $driver instanceof OpenRouterDriver) {
            return;
        }

        $costs = array_map($driver->generationCost(...), $generations);

        if (\in_array(null, $costs, true)) {
            throw new RuntimeException("OpenRouter has no cost yet for run {$run->id}.");
        }

        $run->update(['cost_usd' => round(array_sum($costs), 6)]);
    }

    protected function handleFailure(\Throwable $e): void {}
}
