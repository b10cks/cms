<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiSchedule;
use App\Services\Ai\Schedules\ScheduleRunner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiSchedule */
class AiScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'skill' => $this->skill,
            'scope' => [
                'type' => $this->scope['type'] ?? null,
                'filters' => (object) ($this->scope['filters'] ?? []),
            ],
            'cron' => $this->cron,
            'max_usd' => $this->max_usd,
            'enabled' => $this->enabled,
            'paused_reason' => $this->paused_reason,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => trim("{$this->creator->firstname} {$this->creator->lastname}"),
            ] : null),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'last_run' => $this->whenLoaded('lastRun', fn () => $this->lastRun ? [
                'id' => $this->lastRun->id,
                'state' => $this->lastRun->state->value,
            ] : null),
            'next_run_at' => ScheduleRunner::nextRunAt($this->resource)?->toIso8601String(),
        ];
    }
}
