<?php

namespace App\Services\Automation;

use App\Enums\PeriodType;
use App\Models\Management\Automation;
use App\Models\Management\AutomationExecution;
use App\Models\Management\AutomationUsageStats;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutomationUsageService
{
    public function queueExecution(Automation $automation, array $context = []): AutomationExecution
    {
        $context = $this->withExecutionSnapshot($automation, $context);

        return AutomationExecution::create([
            'automation_id' => $automation->id,
            'status' => 'queued',
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    public function startExecution(
        Automation $automation,
        array $context = [],
        ?AutomationExecution $execution = null
    ): AutomationExecution {
        $context = $context === []
            ? $execution?->context ?? []
            : $this->withExecutionSnapshot($automation, $context);

        if ($execution) {
            $execution->update([
                'status' => 'running',
                'context' => $context,
                'started_at' => now(),
            ]);

            return $execution->refresh();
        }

        return AutomationExecution::create([
            'automation_id' => $automation->id,
            'status' => 'running',
            'context' => $context,
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function completeExecution(AutomationExecution $execution, array $result = []): void
    {
        DB::transaction(function () use ($execution, $result) {
            $execution->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ]);

            $this->incrementExecutionCount($execution->automation);
            $this->aggregateStats($execution);
        });
    }

    public function abortExecution(AutomationExecution $execution, \Throwable $error): void
    {
        $execution->update([
            'status' => 'failed',
            'error' => $error->getMessage(),
            'completed_at' => now(),
        ]);
    }

    public function failExecution(AutomationExecution $execution, \Throwable $error): void
    {
        DB::transaction(function () use ($execution, $error) {
            $execution->update([
                'status' => 'failed',
                'error' => $error->getMessage(),
                'completed_at' => now(),
            ]);

            $this->incrementExecutionCount($execution->automation);
            $this->aggregateStats($execution);
        });
    }

    public function canExecute(Automation $automation): bool
    {
        return $automation->execution_limit === null
            || $automation->execution_count < $automation->execution_limit;
    }

    public function getStatistics(Automation $automation, string $periodType, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = AutomationUsageStats::query()
            ->where('automation_id', $automation->id)
            ->where('period_type', $periodType);

        if ($startDate) {
            $query->where('period_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('period_date', '<=', $endDate);
        }

        return $query->orderBy('period_date')->get();
    }

    public function getRecentExecutions(Automation $automation, int $limit = 50): Collection
    {
        return AutomationExecution::query()
            ->where('automation_id', $automation->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get execution trends for an automation
     */
    public function getExecutionTrends(Automation $automation, PeriodType $periodType = PeriodType::DAILY, int $periods = 30): Collection
    {
        return AutomationUsageStats::query()
            ->where('automation_id', $automation->id)
            ->where('period_type', $periodType->value)
            ->orderByDesc('period_date')
            ->limit($periods)
            ->get();
    }

    protected function incrementExecutionCount(Automation $automation): void
    {
        $automation->increment('execution_count');
    }

    protected function aggregateStats(AutomationExecution $execution): void
    {
        $date = $execution->created_at->startOfDay();
        $periodTypes = PeriodType::default();

        foreach ($periodTypes as $periodType) {
            $periodDate = match ($periodType) {
                PeriodType::DAILY => $date,
                PeriodType::WEEKLY => $date->copy()->startOfWeek(),
                PeriodType::MONTHLY => $date->copy()->startOfMonth(),
                PeriodType::YEARLY => $date->copy()->startOfYear(),
            };

            AutomationUsageStats::updateOrCreate(
                [
                    'automation_id' => $execution->automation_id,
                    'period_type' => $periodType,
                    'period_date' => $periodDate,
                ],
                $this->calculateStats($execution, $periodType, $periodDate)
            );
        }
    }

    protected function calculateStats(AutomationExecution $execution, PeriodType $periodType, Carbon $periodDate): array
    {
        $query = AutomationExecution::query()
            ->where('automation_id', $execution->automation_id)
            ->whereNotNull('completed_at')
            ->where('created_at', '>=', $periodDate)
            ->where('created_at', '<', $periodDate->copy()->add('1 '.$periodType->toCarbonPeriod()));

        $stats = [
            'total_executions' => $query->count(),
            'successful_executions' => (clone $query)->where('status', 'completed')->count(),
            'failed_executions' => (clone $query)->where('status', 'failed')->count(),
        ];

        // Calculate average duration
        $avgDuration = (clone $query)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->select(DB::raw('AVG(duration) as avg_duration'))
            ->first();

        $stats['avg_duration_ms'] = $avgDuration ? $avgDuration->avg_duration : null;

        return $stats;
    }

    protected function withExecutionSnapshot(Automation $automation, array $context): array
    {
        return array_replace_recursive($context, [
            'execution_snapshot' => [
                'version' => 1,
                'automation' => $this->automationSnapshot($automation),
                'action' => $this->actionSnapshot($automation),
                'space' => $this->spaceSnapshot($automation),
            ],
        ]);
    }

    protected function automationSnapshot(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'space_id' => $automation->space_id,
            'action_id' => $automation->action_id,
            'name' => $automation->name,
            'description' => $automation->description,
            'trigger_type' => $automation->trigger_type?->value,
            'trigger' => $automation->trigger?->toArray(),
            'is_active' => $automation->is_active,
            'execution_count' => $automation->execution_count,
            'execution_limit' => $automation->execution_limit,
            'created_at' => $automation->created_at?->toIso8601String(),
            'updated_at' => $automation->updated_at?->toIso8601String(),
        ];
    }

    protected function actionSnapshot(Automation $automation): ?array
    {
        if (! $automation->relationLoaded('action')) {
            $automation->loadMissing('action');
        }

        $action = $automation->action;

        if (! $action) {
            return null;
        }

        return [
            'id' => $action->id,
            'space_id' => $action->space_id,
            'name' => $action->name,
            'description' => $action->description,
            'type' => $action->type?->value,
            'config' => $action->config ?? [],
            'is_active' => $action->is_active,
            'created_at' => $action->created_at?->toIso8601String(),
            'updated_at' => $action->updated_at?->toIso8601String(),
        ];
    }

    protected function spaceSnapshot(Automation $automation): ?array
    {
        if (! $automation->relationLoaded('space')) {
            $automation->loadMissing('space');
        }

        $space = $automation->space;

        if (! $space) {
            return null;
        }

        return [
            'id' => $space->id,
            'name' => $space->name,
        ];
    }
}
