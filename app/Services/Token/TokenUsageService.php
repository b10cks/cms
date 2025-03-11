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
    public function startExecution(Token $token, array $context = [], $start): TokenExecution
    {
        return TokenExecution::create([
            'token_id' => $token->id,
            'status' => 'running',
            'context' => $context,
            'started_at' => $start,
            'created_at' => $start,
        ]);
    }

    public function completeExecution(TokenExecution $execution, array $result = []): void
    {
        dispatch(fn () => \DB::transaction(function () use ($execution, $result) {
            $execution->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now()
            ]);

            self::incrementExecutionCount($execution->token);
            self::aggregateStats($execution);
        })
        )->afterResponse();
    }

    public function failExecution(TokenExecution $execution, \Throwable $error): void
    {
        dispatch(fn () => \DB::transaction(function () use ($execution, $error) {
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
        return TokenExecution::query()
            ->where('token_id', $token->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getExecutionTrends(Token $token, PeriodType $periodType = PeriodType::DAILY, int $periods = 30): Collection
    {
        return TokenUsageStats::query()
            ->where('token_id', $token->id)
            ->where('period_type', $periodType->value)
            ->orderByDesc('period_date')
            ->limit($periods)
            ->get();
    }

    protected static function incrementExecutionCount(Token $token): void
    {
        $token->increment('execution_count');
        $token->update(['last_used_at' => now()]);
    }

    protected static function aggregateStats(TokenExecution $execution): void
    {
        $date = $execution->created_at->startOfDay();

        foreach (PeriodType::default() as $periodType) {
            $periodDate = match ($periodType) {
                PeriodType::DAILY => $date,
                PeriodType::WEEKLY => $date->copy()->startOfWeek(),
                PeriodType::MONTHLY => $date->copy()->startOfMonth(),
                PeriodType::YEARLY => $date->copy()->startOfYear(),
            };

            TokenUsageStats::updateOrCreate(
                [
                    'token_id' => $execution->token_id,
                    'period_type' => $periodType,
                    'period_date' => $periodDate,
                ],
                self::calculateStats($execution, $periodType, $periodDate)
            );
        }
    }

    protected static function calculateStats(TokenExecution $execution, PeriodType $periodType, Carbon $periodDate): array
    {
        $query = TokenExecution::query()
            ->where('token_id', $execution->token_id)
            ->whereNotNull('completed_at')
            ->where('created_at', '>=', $periodDate)
            ->where('created_at', '<', $periodDate->copy()->add('1 ' . $periodType->toCarbonPeriod()));

        $stats = [
            'total_executions' => $query->count(),
            'successful_executions' => (clone $query)->where('status', 'completed')->count(),
            'failed_executions' => (clone $query)->where('status', 'failed')->count(),
        ];

        $avgDuration = (clone $query)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->select(DB::raw('AVG(duration) as avg_duration'))
            ->first();

        $stats['avg_duration_ms'] = $avgDuration ? $avgDuration->avg_duration : null;

        return $stats;
    }
}
