<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Space\AiRun;
use App\Services\Ai\Run\ProviderRetry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiRun
 *
 * @resourceProperty conversation_id shape=string?
 * @resourceProperty skill shape=string?
 * @resourceProperty model shape=string?
 * @resourceProperty reasoning_effort shape=string?
 * @resourceProperty state shape=enum:running|awaiting_input|completed|failed|cancelled
 * @resourceProperty partial shape=boolean
 * @resourceProperty questionnaire shape=object?
 * @resourceProperty result shape=@AiRunResult
 * @resourceProperty error shape=any The failure, or null.
 * @resourceProperty provider_retry shape=@AiProviderRetry? Set once the run has waited on a provider.
 * @resourceProperty policy shape=@AiRunPolicy? The policy snapshot this run read. Null on runs from before snapshots.
 * @resourceProperty operation shape=@AiRunOperation?
 * @resourceProperty event_seq shape=integer
 * @resourceProperty usage shape=@AiRunUsage
 * @resourceProperty started_at shape=date-time?
 * @resourceProperty finished_at shape=date-time?
 */
class AiRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'surface' => $this->surface,
            'skill' => $this->skill,
            'model' => $this->model,
            'reasoning_effort' => $this->reasoning_effort?->value,
            'state' => $this->state->value,
            'mode' => $this->mode,
            'partial' => $this->partial,
            'questionnaire' => $this->questionnaire ? ['questions' => $this->questionnaire['questions']] : null,
            'result' => $this->result,
            'error' => $this->error,
            'provider_retry' => isset($this->setup['provider_retry']) ? ProviderRetry::state($this->resource) : null,
            'policy' => $this->setup['policy'] ?? null,
            'operation' => isset($this->setup['operation']) ? array_intersect_key($this->setup['operation'], array_flip(['id', 'phase', 'batch'])) : null,
            'event_seq' => $this->event_seq,
            'usage' => [
                'steps' => $this->steps,
                'input_tokens' => $this->input_tokens,
                'output_tokens' => $this->output_tokens,
                'cached_tokens' => $this->cached_tokens,
                'cached_tokens' => $this->cached_tokens,
                'cost_usd' => $this->cost_usd,
                'cost_confirmed_at' => $this->cost_confirmed_at?->toIso8601String(),
            ],
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
