<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiRun */
class AiRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'surface' => $this->surface,
            'skill' => $this->skill,
            'model' => $this->model,
            'state' => $this->state->value,
            'mode' => $this->mode,
            'partial' => $this->partial,
            'questionnaire' => $this->questionnaire ? ['questions' => $this->questionnaire['questions']] : null,
            'result' => $this->result,
            'error' => $this->error,
            'event_seq' => $this->event_seq,
            'usage' => [
                'steps' => $this->steps,
                'input_tokens' => $this->input_tokens,
                'output_tokens' => $this->output_tokens,
                'cached_tokens' => $this->cached_tokens,
                'cached_tokens' => $this->cached_tokens,
                'cost_usd' => $this->cost_usd,
            ],
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
