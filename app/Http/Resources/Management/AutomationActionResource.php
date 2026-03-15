<?php

namespace App\Http\Resources\Management;

use App\Models\Management\AutomationAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutomationAction
 */
class AutomationActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type?->value,
            'config' => $this->config ?? [],
            'is_active' => $this->is_active,
            'has_secrets' => ! empty($this->secrets),
            'secret_keys' => array_keys($this->secrets ?? []),
            'automations_count' => $this->whenCounted('automations'),
            'last_executed_at' => $this->last_executed_at?->toIso8601String(),
            'last_execution_status' => $this->last_execution_status,
            'last_execution_error' => $this->last_execution_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
