<?php

namespace App\Services\Ai\Changesets;

use App\Enums\AiRunState;
use App\Models\Management\AiRun;
use App\Services\Ai\Dto\AiModelDto;

/**
 * Rough cost of a run over a set of entries. Once a space has finished
 * MIN_RUNS runs of the skill, the estimate is their average cost per entry;
 * before that it comes from the entries' source token counts and the model's
 * price. Each entry is read once and rewritten once; every step also resends
 * instructions and tool definitions.
 */
final class PreflightEstimate
{
    private const STEP_OVERHEAD_TOKENS = 2000;

    private const OUTPUT_OVERHEAD_TOKENS = 200;

    public const MIN_RUNS = 5;

    /**
     * @param  list<array{tokens: int}>  $entries
     * @param  float|null  $observed  average cost per entry, see observedCostPerEntry()
     * @return array{input_tokens: int, output_tokens: int, cost_usd: float}
     */
    public static function for(array $entries, ?AiModelDto $model, ?float $observed = null): array
    {
        $source = array_sum(array_column($entries, 'tokens'));
        $input = \count($entries) * self::STEP_OVERHEAD_TOKENS + 2 * $source;
        $output = \count($entries) * self::OUTPUT_OVERHEAD_TOKENS + $source;

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => $observed !== null
                ? round($observed * \count($entries), 4)
                : round($model ? ($input * $model->inputCost + $output * $model->outputCost) / 1_000_000 : 0.0, 4),
        ];
    }

    /**
     * Average cost per entry over the space's finished runs of a skill, or
     * null until MIN_RUNS of them exist. Entries are the distinct targets of
     * a run's changeset; runs without proposals do not count.
     */
    public static function observedCostPerEntry(string $spaceId, ?string $skill): ?float
    {
        if ($skill === null) {
            return null;
        }

        $perRun = AiRun::query()
            ->toBase()
            ->from('ai_runs as r')
            ->join('ai_changesets as c', 'c.run_id', '=', 'r.id')
            ->join('ai_changeset_items as i', 'i.changeset_id', '=', 'c.id')
            ->where('r.space_id', $spaceId)
            ->where('r.skill', $skill)
            ->where('r.state', AiRunState::Completed->value)
            ->where('r.cost_usd', '>', 0)
            ->groupBy('r.id', 'r.cost_usd')
            ->selectRaw('r.cost_usd as cost, count(distinct coalesce(i.target_id, i.id)) as entries');

        $total = AiRun::query()->toBase()->fromSub($perRun, 'p')
            ->selectRaw('count(*) as runs, sum(cost) as cost, sum(entries) as entries')
            ->first();

        return $total->runs >= self::MIN_RUNS ? (float) $total->cost / (int) $total->entries : null;
    }
}
