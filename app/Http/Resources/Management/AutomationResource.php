<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Automation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Automation
 */
class AutomationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $trigger = $this->trigger?->toArray();

        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'action_id' => $this->action_id,
            'name' => $this->name,
            'description' => $this->description,
            'trigger_type' => $this->trigger_type?->value,
            'trigger' => $trigger,
            'action' => $this->whenLoaded('action', fn () => new AutomationActionResource($this->action)),
            'is_active' => $this->is_active,
            'execution_count' => $this->execution_count,
            'execution_limit' => $this->execution_limit,
            'remaining_executions' => $this->execution_limit
                ? $this->execution_limit - $this->execution_count
                : null,
            'last_triggered_at' => $this->last_triggered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
