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
        return [
            'id' => $this->id,
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
}
