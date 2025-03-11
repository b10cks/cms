<?php

namespace App\Http\Resources\Management;

use App\Models\Management\TokenUsageStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TokenUsageStats
 */
class TokenUsageStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_type' => $this->period_type->value,
            'period_date' => $this->period_date->toDateString(),
            'total_executions' => $this->total_executions,
            'successful_executions' => $this->successful_executions,
            'failed_executions' => $this->failed_executions,
            'avg_duration_ms' => $this->avg_duration_ms,
            'success_rate' => $this->total_executions > 0
                ? round(($this->successful_executions / $this->total_executions) * 100, 2)
                : 0,
        ];
    }
}
