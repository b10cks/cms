<?php

namespace App\Http\Resources\Management;

use App\Models\Management\AutomationExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutomationExecution
 */
class AutomationExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $automationSnapshot = $this->automationSnapshot();

        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'automation' => $automationSnapshot
                ?? $this->whenLoaded('automation', fn () => new AutomationResource($this->automation)),
            'status' => $this->status,
            'context' => $this->context,
            'result' => $this->result,
            'error' => $this->error,
            'duration' => $this->duration,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function automationSnapshot(): ?array
    {
        $automation = data_get($this->context, 'execution_snapshot.automation');

        if (! is_array($automation)) {
            return null;
        }

        $action = data_get($this->context, 'execution_snapshot.action');

        return [
            ...$automation,
            'action' => is_array($action) ? $action : null,
        ];
    }
}
