<?php

namespace App\Services\Ai\Changesets;

use App\Services\Ai\Dto\AiModelDto;

/**
 * Rough cost of a run over a set of entries, from their source token counts
 * and the model's price. Each entry is read once and rewritten once; every
 * step also resends instructions and tool definitions.
 */
final class PreflightEstimate
{
    private const STEP_OVERHEAD_TOKENS = 2000;

    private const OUTPUT_OVERHEAD_TOKENS = 200;

    /**
     * @param  list<array{tokens: int}>  $entries
     * @return array{input_tokens: int, output_tokens: int, cost_usd: float}
     */
    public static function for(array $entries, ?AiModelDto $model): array
    {
        $source = array_sum(array_column($entries, 'tokens'));
        $input = \count($entries) * self::STEP_OVERHEAD_TOKENS + 2 * $source;
        $output = \count($entries) * self::OUTPUT_OVERHEAD_TOKENS + $source;

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => round($model ? ($input * $model->inputCost + $output * $model->outputCost) / 1_000_000 : 0.0, 4),
        ];
    }
}
