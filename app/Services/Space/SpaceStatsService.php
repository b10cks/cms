<?php

namespace App\Services\Space;

use App\Enums\PeriodType;
use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SpaceStatsService
{
    /**
     * Get comprehensive stats for a space's dashboard
     *
     * @param Space $space
     * @param array $options
     * @return array
     */
    public function getDashboardStats(Space $space, array $options = []): array
    {
        $periodType = $options['period_type'] ?? PeriodType::DAILY;
        $startDate = $options['start_date'] ?? Carbon::now()->subDays(30);
        $endDate = $options['end_date'] ?? Carbon::now();
        $cacheMinutes = $options['cache_minutes'] ?? 10;

        return Cache::remember(
            "space_stats:{$space->id}:{$periodType->value}:{$startDate->timestamp}:{$endDate->timestamp}",
            now()->addMinutes($cacheMinutes),
            function () use ($space, $periodType, $startDate, $endDate) {
                return [
                    'content' => $this->getContentStats($space, $startDate, $endDate),
                    'user_activity' => $this->getUserActivityStats($space, $startDate, $endDate),
                    'system' => $this->getSystemStats($space, $startDate, $endDate),
                    'trends' => $this->getTrendStats($space, $periodType, $startDate, $endDate),
                ];
            }
        );
    }

    /**
     * Get content-related statistics
     */
    public function getContentStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $blocks = Block::count();
        $contents = Content::count();
        $published = Content::whereNotNull('published_at')->count();
        $draft = Content::whereNull('published_at')->count();

        // Get content by block type distribution
        $contentByType = Content::select('block_id', DB::raw('count(*) as count'))
            ->whereHas('block')
            ->with('block:id,name')
            ->groupBy('block_id')
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->block->name ?? 'Unknown',
                    'count' => $item->count
                ];
            });

        // Get content creation over time
        $contentCreationTrend = Content::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Get language distribution
        $languageDistribution = Content::select('language_iso', DB::raw('count(*) as count'))
            ->groupBy('language_iso')
            ->get()
            ->pluck('count', 'language_iso')
            ->toArray();

        return [
            'count' => [
                'total' => $contents,
                'published' => $published,
                'draft' => $draft,
                'blocks' => $blocks
            ],
            'by_type' => $contentByType,
            'creation_trend' => $contentCreationTrend,
            'languages' => $languageDistribution,
        ];
    }

    /**
     * Get user activity statistics
     */
    public function getUserActivityStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        // Get users associated with the space
        $users = $space->users;
        $userIds = $users->pluck('id')->toArray();

        // Get recent logins
        $recentLogins = User::whereIn('id', $userIds)
            ->whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->limit(10)
            ->get(['id', 'firstname', 'lastname', 'last_login_at'])
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'last_login' => $user->last_login_at?->diffForHumans()
                ];
            });

        // Get role distribution
        $roleDistribution = DB::table('space_user')
            ->where('space_id', $space->id)
            ->select('role', DB::raw('count(*) as count'))
            ->groupBy('role')
            ->get()
            ->pluck('count', 'role')
            ->toArray();

        // Get content activity by user (top contributors)
//        $contentByUser = Content::select('created_by', DB::raw('count(*) as count'))
//            ->whereNotNull('created_by')
//            ->whereIn('created_by', $userIds)
//            ->whereBetween('created_at', [$startDate, $endDate])
//            ->groupBy('created_by')
//            ->orderByDesc('count')
//            ->limit(5)
//            ->get()
//            ->map(function ($item) {
//                $user = User::find($item->created_by);
//                return [
//                    'id' => $item->created_by,
//                    'name' => $user?->name ?? 'Unknown',
//                    'count' => $item->count
//                ];
//            });

        return [
            'total_users' => $users->count(),
            'recent_logins' => $recentLogins,
            'role_distribution' => $roleDistribution,
//            'top_contributors' => $contentByUser,
        ];
    }

    /**
     * Get system performance statistics
     */
    public function getSystemStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        // Token usage stats
        $tokens = Token::where('space_id', $space->id)->get();
        $tokenIds = $tokens->pluck('id')->toArray();

        $apiRequests = DB::table('token_executions')
            ->whereIn('token_id', $tokenIds)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->count();

        $apiSuccessRate = DB::table('token_executions')
            ->whereIn('token_id', $tokenIds)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful')
            )
            ->first();

        $successRate = $apiSuccessRate->total > 0
            ? round(($apiSuccessRate->successful / $apiSuccessRate->total) * 100, 2)
            : 0;

        $avgResponseTime = DB::table('token_executions')
            ->whereIn('token_id', $tokenIds)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->whereNotNull('duration')
            ->avg('duration') ?? 0;

        // Content publishing frequency
        $publishingFrequency = Content::select(
            DB::raw('DATE(published_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        return [
            'api' => [
                'total_requests' => $apiRequests,
                'success_rate' => $successRate,
                'avg_response_time_ms' => round($avgResponseTime, 2),
            ],
            'publishing_frequency' => $publishingFrequency,
        ];
    }

    /**
     * Get trend statistics over time
     */
    public function getTrendStats(Space $space, PeriodType $periodType, Carbon $startDate, Carbon $endDate): array
    {
        $interval = match ($periodType) {
            PeriodType::DAILY => 'day',
            PeriodType::WEEKLY => 'week',
            PeriodType::MONTHLY => 'month',
            PeriodType::YEARLY => 'year',
            default => 'day'
        };

        $periods = [];
        $current = clone $startDate;

        while ($current <= $endDate) {
            $periods[] = $current->format('Y-m-d');
            $current->add(1, $interval);
        }

        // Get content creation trend
        $contentTrend = $this->getTrendData(Content::class, 'created_at', $periods, $periodType);

        // Get content publishing trend
        $publishingTrend = $this->getTrendData(Content::class, 'published_at', $periods, $periodType);

        // Get API usage trend
        $apiTrend = $this->getApiUsageTrend($space, $periods, $periodType);

        return [
            'periods' => $periods,
            'content_creation' => $contentTrend,
            'content_publishing' => $publishingTrend,
            'api_usage' => $apiTrend,
        ];
    }

    /**
     * Get trend data for a specific model and date field
     */
    private function getTrendData(string $model, string $dateField, array $periods, PeriodType $periodType): array
    {
        $dateFormat = match ($periodType) {
            PeriodType::DAILY => 'Y-m-d',
            PeriodType::WEEKLY => 'Y-W',
            PeriodType::MONTHLY => 'Y-m',
            PeriodType::YEARLY => 'Y',
            default => 'Y-m-d'
        };

        $data = $model::selectRaw("DATE_FORMAT($dateField, ?) as period, COUNT(*) as count", [$dateFormat])
            ->whereNotNull($dateField)
            ->groupBy('period')
            ->pluck('count', 'period')
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($dateFormat);
            $result[$period] = $data[$formattedPeriod] ?? 0;
        }

        return $result;
    }

    /**
     * Get API usage trend for a space
     */
    private function getApiUsageTrend(Space $space, array $periods, PeriodType $periodType): array
    {
        $dateFormat = match ($periodType) {
            PeriodType::DAILY => 'Y-m-d',
            PeriodType::WEEKLY => 'Y-W',
            PeriodType::MONTHLY => 'Y-m',
            PeriodType::YEARLY => 'Y',
            default => 'Y-m-d'
        };

        $tokenIds = Token::where('space_id', $space->id)->pluck('id')->toArray();

        $data = DB::table('token_executions')
            ->selectRaw("DATE_FORMAT(started_at, ?) as period, COUNT(*) as count", [$dateFormat])
            ->whereIn('token_id', $tokenIds)
            ->groupBy('period')
            ->pluck('count', 'period')
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($dateFormat);
            $result[$period] = $data[$formattedPeriod] ?? 0;
        }

        return $result;
    }
}
