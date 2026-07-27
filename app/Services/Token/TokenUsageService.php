<?php

namespace App\Services\Token;

use App\Enums\PeriodType;
use App\Models\Management\Token;
use App\Models\Management\TokenExecution;
use App\Models\Management\TokenUsageStats;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TokenUsageService
{
    protected static function trackingEnabled(): bool
    {
        return config('app.track_usage', false);
    }

    public function startExecution(Token $token, $start): ?TokenExecution
    {
        if (!self::trackingEnabled()) {
            return null;
        }

        return TokenExecution::create([
            'token_id' => $token->id,
            'status' => 'running',
            'started_at' => $start,
        ]);
    }

    public function completeExecution(?TokenExecution $execution): void
    {
        if (!self::trackingEnabled() || !$execution) {
            return;
        }

        dispatch(fn () => \DB::transaction(function () use ($execution) {
            $execution->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            self::incrementExecutionCount($execution->token);
            self::aggregateStats($execution);
        })
        )->afterResponse();
    }

    public function failExecution(?TokenExecution $execution, \Throwable $error): void
    {
        if (!self::trackingEnabled() || !$execution) {
            return;
        }

        dispatch(fn() => \DB::transaction(function () use ($execution, $error) {
            $execution->update([
                'status' => 'failed',
                'error' => $error->getMessage(),
                'completed_at' => now()
            ]);

            self::incrementExecutionCount($execution->token);
            self::aggregateStats($execution);
        })
        )->afterResponse();
    }

    public function canExecute(Token $token): bool
    {
        return $token->execution_limit === null
            || $token->execution_count < $token->execution_limit;
    }

    public function getStatistics(Token $token, string $periodType, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        if (!self::trackingEnabled()) {
            return collect();
        }

        $query = TokenUsageStats::query()
            ->where('token_id', $token->id)
            ->where('period_type', $periodType);

        if ($startDate) {
            $query->where('period_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('period_date', '<=', $endDate);
        }

        return $query->orderBy('period_date')->get();
    }

    public function getRecentExecutions(Token $token, int $limit = 50): Collection
    {
        if (!self::trackingEnabled()) {
            return collect();
        }

        return TokenExecution::query()
            ->where('token_id', $token->id)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }

    public function getExecutionTrends(Token $token, PeriodType $periodType = PeriodType::DAILY, int $periods = 30): Collection
    {
        if (!self::trackingEnabled()) {
            return collect();
        }

        return TokenUsageStats::query()
            ->where('token_id', $token->id)
            ->where('period_type', $periodType->value)
            ->orderByDesc('period_date')
            ->limit($periods)
            ->get();
    }

    protected static function incrementExecutionCount(Token $token): void
    {
        if (!self::trackingEnabled()) {
            return;
        }

        $token->increment('execution_count');
        $token->update(['last_used_at' => now()]);
    }

    protected static function aggregateStats(TokenExecution $execution): void
    {
        if (!self::trackingEnabled()) {
            return;
        }

        $date = $execution->started_at->startOfDay();
        $failed = $execution->status === 'failed';
        $duration = $execution->duration;

        foreach (PeriodType::default() as $periodType) {
            $periodDate = match ($periodType) {
                PeriodType::DAILY => $date,
                PeriodType::WEEKLY => $date->copy()->startOfWeek(),
                PeriodType::MONTHLY => $date->copy()->startOfMonth(),
                PeriodType::YEARLY => $date->copy()->startOfYear(),
            };

            // Atomic incremental upsert: avg_duration_ms must be assigned first so
            // it reads the pre-increment total_executions on MySQL (assignments are
            // evaluated left to right in ON DUPLICATE KEY UPDATE).
            TokenUsageStats::upsert(
                [[
                    'token_id' => $execution->token_id,
                    'period_type' => $periodType->value,
                    'period_date' => $periodDate->toDateString(),
                    'total_executions' => 1,
                    'successful_executions' => $failed ? 0 : 1,
                    'failed_executions' => $failed ? 1 : 0,
                    'avg_duration_ms' => $duration,
                ]],
                ['token_id', 'period_type', 'period_date'],
                [
                    'avg_duration_ms' => $duration === null
                        ? DB::raw('avg_duration_ms')
                        : DB::raw(sprintf(
                            'coalesce(avg_duration_ms, 0) + (%F - coalesce(avg_duration_ms, 0)) / (total_executions + 1)',
                            $duration
                        )),
                    'total_executions' => DB::raw('total_executions + 1'),
                    'successful_executions' => DB::raw('successful_executions + ' . ($failed ? 0 : 1)),
                    'failed_executions' => DB::raw('failed_executions + ' . ($failed ? 1 : 0)),
                ]
            );
        }
    }
}
