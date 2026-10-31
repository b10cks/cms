<?php

namespace App\Http\Resources\Management;

use App\Services\Ai\ModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpaceAiConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $model = app(ModelRegistry::class)->findModel("{$this->driver}:{$this->model}");

        return [
            'id' => $this->id,
            'name' => $this->name,
            'driver' => $this->driver,
            'model' => $this->model,
            'model_full_id' => $model?->getFullId() ?? "{$this->driver}:{$this->model}",
            'model_capabilities' => $model?->capabilities ?? [],
            'supports_streaming' => $model?->supportsStreaming ?? false,
            'supports_tools' => $model?->supportsTools ?? false,
            'supports_vision' => $model?->supportsVision ?? false,
            // From the cached catalogue; null when the model is gone upstream or the catalogue failed to load.
            'model_info' => $model ? [
                'name' => $model->name,
                'supports_vision' => $model->supportsVision,
                'supports_tools' => $model->supportsTools,
                'supports_reasoning' => $model->supportsReasoning,
                'reasoning_required' => $model->reasoningRequired,
            ] : null,
            'system_prompt' => $this->system_prompt,
            'temperature' => (float) $this->temperature,
            'max_tokens' => (int) $this->max_tokens,
            // Null means the default level, see AiReasoningEffort::DEFAULT.
            'reasoning_effort' => $this->reasoning_effort?->value,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
