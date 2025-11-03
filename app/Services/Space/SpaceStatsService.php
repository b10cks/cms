<?php

namespace App\Services\Space;

use App\Enums\PeriodType;
use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SpaceStatsService
{
    /**
     * Get comprehensive dashboard statistics for a space
     */
    public function getDashboardStats(Space $space, array $options = []): array
    {
        $periodType = $options['period_type'] ?? PeriodType::DAILY;
        $startDate = $options['start_date'] ?? Carbon::now()->subDays(30);
        $endDate = $options['end_date'] ?? Carbon::now();
        $cacheMinutes = $options['cache_minutes'] ?? 10;

//        return Cache::remember(
//            "space_stats:{$space->id}:{$periodType->value}:{$startDate->timestamp}:{$endDate->timestamp}",
//            now()->addMinutes($cacheMinutes),
//            function () use ($space, $periodType, $startDate, $endDate) {
                return [
                    'content' => $this->getContentStats($space, $startDate, $endDate),
                    'user_activity' => $this->getUserActivityStats($space, $startDate, $endDate),
                    'system' => $this->getSystemStats($space, $startDate, $endDate),
                    'trends' => $this->getTrendStats($space, $periodType, $startDate, $endDate),
                ];
//            }
//        );
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
        $users = $space->users;
        $userIds = $users->pluck('id')->toArray();

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

        $roleDistribution = DB::table('space_user')
            ->where('space_id', $space->id)
            ->select('role', DB::raw('count(*) as count'))
            ->groupBy('role')
            ->get()
            ->pluck('count', 'role')
            ->toArray();


        return [
            'total_users' => $users->count(),
            'recent_logins' => $recentLogins,
            'role_distribution' => $roleDistribution,
        ];
    }

    /**
     * Get system statistics including API and traffic metrics
     */
    public function getSystemStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $apiStats = $this->getApiStats($space, $startDate, $endDate);
        $trafficStats = $this->getTrafficStats($space, $startDate, $endDate);
        $publishingFrequency = $this->getPublishingFrequency($startDate, $endDate);

        return [
            'api' => $apiStats,
            'traffic' => $trafficStats,
            'publishing_frequency' => $publishingFrequency,
        ];
    }

    /**
     * Get API usage statistics from SpaceApiHitHourly
     */
    private function getApiStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $stats = SpaceApiHitHourly::where('space_id', $space->id)
            ->whereBetween('hour_timestamp', [$startDate, $endDate])
            ->select(
                DB::raw('SUM(hit_count) as total_requests'),
                DB::raw('SUM(success_count) as successful_requests'),
                DB::raw('SUM(error_count) as error_requests'),
                DB::raw('AVG(time_taken) as avg_response_time')
            )
            ->first();

        $totalRequests = $stats->total_requests ?? 0;
        $successfulRequests = $totalRequests - ($stats->error_requests ?? 0);
        $successRate = $totalRequests > 0
            ? round(($successfulRequests / $totalRequests) * 100, 2)
            : 0;

        return [
            'total_requests' => (int) $totalRequests,
            'success_rate' => $successRate,
            'error_rate' => round(100 - $successRate, 2),
            'avg_response_time_ms' => round($stats->avg_response_time ?? 0, 2),
        ];
    }

    /**
     * Get traffic usage statistics from SpaceTrafficUsageHourly
     */
    private function getTrafficStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $stats = SpaceTrafficUsageHourly::where('space_id', $space->id)
            ->whereBetween('hour_timestamp', [$startDate, $endDate])
            ->select(
                DB::raw('SUM(bytes_sent) as total_bytes_sent'),
                DB::raw('SUM(bytes_received) as total_bytes_received'),
                DB::raw('SUM(total_bytes) as total_bytes'),
                DB::raw('SUM(request_count) as total_requests'),
                DB::raw('SUM(cache_hits) as total_cache_hits'),
                DB::raw('SUM(cache_misses) as total_cache_misses')
            )
            ->first();

        $totalCacheRequests = ($stats->total_cache_hits ?? 0) + ($stats->total_cache_misses ?? 0);
        $cacheHitRate = $totalCacheRequests > 0
            ? round((($stats->total_cache_hits ?? 0) / $totalCacheRequests) * 100, 2)
            : 0;

        return [
            'bytes_sent' => (int) ($stats->total_bytes_sent ?? 0),
            'bytes_received' => (int) ($stats->total_bytes_received ?? 0),
            'total_bytes' => (int) ($stats->total_bytes ?? 0),
            'request_count' => (int) ($stats->total_requests ?? 0),
            'cache_hits' => (int) ($stats->total_cache_hits ?? 0),
            'cache_misses' => (int) ($stats->total_cache_misses ?? 0),
            'cache_hit_rate' => $cacheHitRate,
        ];
    }

    /**
     * Get publishing frequency over time
     */
    private function getPublishingFrequency(Carbon $startDate, Carbon $endDate): array
    {
        return Content::select(
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
    }

    /**
     * Get trend statistics over time periods
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

        $periods = $this->generatePeriods($startDate, $endDate, $interval);
        $dateFormat = $this->getDateFormat($periodType);

        $contentTrend = $this->getTrendData(Content::class, 'created_at', $periods, $dateFormat);
        $publishingTrend = $this->getTrendData(Content::class, 'published_at', $periods, $dateFormat);
        $apiTrend = $this->getApiUsageTrend($space, $periods, $dateFormat);
        $trafficTrend = $this->getTrafficUsageTrend($space, $periods, $dateFormat);

        return [
            'periods' => $periods,
            'content_creation' => $contentTrend,
            'content_publishing' => $publishingTrend,
            'api_usage' => $apiTrend,
            'traffic_usage' => $trafficTrend,
        ];
    }

    /**
     * Generate period array based on interval
     */
    private function generatePeriods(Carbon $startDate, Carbon $endDate, string $interval): array
    {
        $periods = [];
        $current = clone $startDate;

        while ($current <= $endDate) {
            $periods[] = $current->format('Y-m-d');
            $current->add(1, $interval);
        }

        return $periods;
    }

    /**
     * Get date format based on period type
     */
    private function getDateFormat(PeriodType $periodType): string
    {
        return match ($periodType) {
            PeriodType::DAILY => 'Y-m-d',
            PeriodType::WEEKLY => 'Y-W',
            PeriodType::MONTHLY => 'Y-m',
            PeriodType::YEARLY => 'Y',
            default => 'Y-m-d'
        };
    }

    /**
     * Get trend data for a model's date field
     */
    private function getTrendData(string $model, string $dateField, array $periods, string $dateFormat): array
    {
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
    private function getApiUsageTrend(Space $space, array $periods, string $dateFormat): array
    {
        $data = SpaceApiHitHourly::where('space_id', $space->id)
            ->selectRaw("DATE_FORMAT(hour_timestamp, ?) as period, SUM(hit_count) as count", [$dateFormat])
            ->groupBy('period')
            ->pluck('count', 'period')
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($dateFormat);
            $result[$period] = (int) ($data[$formattedPeriod] ?? 0);
        }

        return $result;
    }

    /**
     * Get traffic usage trend from SpaceTrafficUsageHourly
     */
    private function getTrafficUsageTrend(Space $space, array $periods, string $dateFormat): array
    {
        $data = SpaceTrafficUsageHourly::where('space_id', $space->id)
            ->selectRaw(
                "DATE_FORMAT(hour_timestamp, ?) as period,
                SUM(total_bytes) as total_bytes,
                SUM(request_count) as request_count",
                [$dateFormat]
            )
            ->groupBy('period')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item->period => [
                        'total_bytes' => (int) $item->total_bytes,
                        'request_count' => (int) $item->request_count,
                    ]
                ];
            })
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($dateFormat);
            $result[$period] = $data[$formattedPeriod] ?? [
                'total_bytes' => 0,
                'request_count' => 0,
            ];
        }

        return $result;
    }
}
