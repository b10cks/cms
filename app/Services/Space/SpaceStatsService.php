<?php

namespace App\Services\Space;

use App\Enums\PeriodType;
use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Space\Asset;
use App\Models\Space\AuditLog;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\Space\Redirect;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
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
        $includeActivity = (bool) ($options['include_activity'] ?? false);

        return Cache::remember(
            "space_stats:{$space->id}:{$periodType->value}:{$startDate->timestamp}:{$endDate->timestamp}:".($includeActivity ? '1' : '0'),
            now()->addMinutes($cacheMinutes),
            function () use ($space, $periodType, $startDate, $endDate, $includeActivity) {
                $stats = [
                    'content' => $this->getContentStats($space, $startDate, $endDate),
                    'assets' => $this->getAssetStats($space, $startDate, $endDate),
                    'redirects' => $this->getRedirectStats($space, $startDate, $endDate),
                    'data_sources' => $this->getDataSourceStats($space, $startDate, $endDate),
                    'user_activity' => $this->getUserActivityStats($space, $startDate, $endDate),
                    'system' => $this->getSystemStats($space, $startDate, $endDate),
                    'trends' => $this->getTrendStats($space, $periodType, $startDate, $endDate),
                ];

                // Only exposed to users who may read the audit log itself.
                if ($includeActivity) {
                    $stats['activity'] = $this->getActivityCalendar($endDate);
                }

                return $stats;
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
        $newContent = Content::whereBetween('created_at', [$startDate, $endDate])->count();
        $newBlocks = Block::whereBetween('created_at', [$startDate, $endDate])->count();

        $contentByType = Content::select('block_id', DB::raw('count(*) as count'))
            ->whereHas('block')
            ->with('block:id,name,color,icon')
            ->groupBy('block_id')
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->block->name ?? 'Unknown',
                    'icon' => $item->block->icon ?? null,
                    'color' => $item->block->color ?? null,
                    'count' => $item->count,
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
                'new' => $newContent,
                'published' => $published,
                'draft' => $draft,
                'blocks' => $blocks,
                'new_blocks' => $newBlocks,
            ],
            'by_type' => $contentByType,
            'creation_trend' => $contentCreationTrend,
            'languages' => $languageDistribution,
        ];
    }

    /**
     * Get asset-related statistics
     */
    public function getAssetStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $totalAssets = Asset::count();
        $newAssetSize = (int) (Asset::whereBetween('created_at', [$startDate, $endDate])->sum('size') ?? 0);

        $assetsByType = Asset::select('mime_type', DB::raw('count(*) as count'))
            ->groupBy('mime_type')
            ->get()
            ->map(function ($item) {
                $type = $this->categorizeAssetType($item->mime_type);

                return [
                    'mime_type' => $item->mime_type,
                    'category' => $type,
                    'count' => $item->count,
                ];
            })
            ->groupBy('category')
            ->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'count' => $items->sum('count'),
                    'types' => $items->pluck('count', 'mime_type')->toArray(),
                ];
            })
            ->values();

        $storageStats = Asset::select(
            DB::raw('SUM(size) as total_size'),
            DB::raw('AVG(size) as avg_size'),
            DB::raw('MAX(size) as max_size'),
            DB::raw('MIN(size) as min_size')
        )->first();

        $recentAssets = Asset::whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'filename', 'size', 'mime_type', 'created_at'])
            ->map(function ($asset) {
                return [
                    'id' => $asset->id,
                    'filename' => $asset->filename,
                    'size' => $asset->size,
                    'type' => $this->categorizeAssetType($asset->mime_type),
                    'uploaded_at' => $asset->created_at->diffForHumans(),
                ];
            });

        $uploadTrend = Asset::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count'),
            DB::raw('SUM(size) as total_size')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'count' => $item->count,
                    'total_size' => (int) $item->total_size,
                ];
            })
            ->pluck(null, 'date')
            ->toArray();

        return [
            'count' => [
                'total' => $totalAssets,
                'by_type' => $assetsByType,
            ],
            'storage' => [
                'total_size' => (int) ($storageStats->total_size ?? 0),
                'new_size' => $newAssetSize,
                'avg_size' => (int) ($storageStats->avg_size ?? 0),
                'max_size' => (int) ($storageStats->max_size ?? 0),
            ],
            'recent_uploads' => $recentAssets,
            'upload_trend' => $uploadTrend,
        ];
    }

    /**
     * Get redirect-related statistics
     */
    public function getRedirectStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $totalRedirects = Redirect::count();
        $newRedirects = Redirect::whereBetween('created_at', [$startDate, $endDate])->count();
        //        $activeRedirects = Redirect::where('is_active', true)->count();
        //        $inactiveRedirects = Redirect::where('is_active', false)->count();

        $redirectsByStatusCode = Redirect::select('status_code', DB::raw('count(*) as count'))
            ->groupBy('status_code')
            ->get()
            ->pluck('count', 'status_code')
            ->toArray();

        $recentlyCreated = Redirect::whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'source', 'target', 'status_code', 'created_at'])
            ->map(function ($redirect) {
                return [
                    'id' => $redirect->id,
                    'source' => $redirect->source,
                    'target' => $redirect->target,
                    'status_code' => $redirect->status_code,
                    'created_at' => $redirect->created_at->diffForHumans(),
                ];
            });

        $creationTrend = Redirect::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $mostUsedRedirects = Redirect::select('id', 'source', 'target', 'hits')
            ->where('hits', '>', 0)
            ->orderByDesc('hits')
            ->limit(10)
            ->get()
            ->map(function ($redirect) {
                return [
                    'id' => $redirect->id,
                    'source' => $redirect->source,
                    'target' => $redirect->target,
                    'hits' => $redirect->hits,
                ];
            });

        return [
            'count' => [
                'total' => $totalRedirects,
                'new' => $newRedirects,
                //                'active' => $activeRedirects,
                //                'inactive' => $inactiveRedirects,
            ],
            'by_status_code' => $redirectsByStatusCode,
            'recent_redirects' => $recentlyCreated,
            'creation_trend' => $creationTrend,
            'most_used' => $mostUsedRedirects,
        ];
    }

    /**
     * Get data source and data entry statistics
     */
    public function getDataSourceStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $totalDataSources = DataSource::count();
        $activeDataSources = DataSource::where('is_active', true)->count();
        $newDataSources = DataSource::whereBetween('created_at', [$startDate, $endDate])->count();

        $dataSourcesWithEntryCount = DataSource::withCount('entries')
            ->orderByDesc('entries_count')
            ->limit(10)
            ->get(['id', 'name', 'is_active'])
            ->map(function ($dataSource) {
                return [
                    'id' => $dataSource->id,
                    'name' => $dataSource->name,
                    'is_active' => $dataSource->is_active,
                    'entry_count' => $dataSource->entries_count,
                ];
            });

        $totalEntries = DataEntry::count();

        $entriesByDataSource = DataEntry::select('data_source_id', DB::raw('count(*) as count'))
            ->with('dataSource:id,name')
            ->groupBy('data_source_id')
            ->get()
            ->map(function ($item) {
                return [
                    'data_source_id' => $item->data_source_id,
                    'data_source_name' => $item->dataSource->name ?? 'Unknown',
                    'count' => $item->count,
                ];
            });

        $entryCreationTrend = DataEntry::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count')
        )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $recentEntries = DataEntry::with('dataSource:id,name')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'data_source_id', 'created_at'])
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'data_source' => $entry->dataSource->name ?? 'Unknown',
                    'created_at' => $entry->created_at->diffForHumans(),
                ];
            });

        return [
            'data_sources' => [
                'count' => [
                    'total' => $totalDataSources,
                    'new' => $newDataSources,
                    'active' => $activeDataSources,
                    'inactive' => $totalDataSources - $activeDataSources,
                ],
                'top_sources' => $dataSourcesWithEntryCount,
            ],
            'data_entries' => [
                'count' => [
                    'total' => $totalEntries,
                ],
                'by_data_source' => $entriesByDataSource,
                'creation_trend' => $entryCreationTrend,
                'recent_entries' => $recentEntries,
            ],
        ];
    }

    /**
     * Get user activity statistics
     */
    public function getUserActivityStats(Space $space, Carbon $startDate, Carbon $endDate): array
    {
        $users = $space->users;
        $userIds = $users->pluck('id')->toArray();
        $newUsers = DB::table('space_user')
            ->where('space_id', $space->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $recentLogins = User::whereIn('id', $userIds)
            ->whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->limit(10)
            ->get(['id', 'firstname', 'lastname', 'last_login_at'])
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'last_login' => $user->last_login_at?->diffForHumans(),
                ];
            });

        $roleDistribution = DB::table('space_user')
            ->join('roles', 'roles.id', '=', 'space_user.role_id')
            ->where('space_id', $space->id)
            ->select('roles.key', DB::raw('count(*) as count'))
            ->groupBy('roles.key')
            ->get()
            ->pluck('count', 'key')
            ->toArray();

        return [
            'total_users' => $users->count(),
            'new_users' => $newUsers,
            'recent_logins' => $recentLogins,
            'role_distribution' => $roleDistribution,
        ];
    }

    /**
     * Build a GitHub-style contribution calendar from the space audit log.
     *
     * The window is a fixed number of whole weeks ending on $endDate, independent
     * of the dashboard date range, so the grid always renders as a full calendar.
     */
    public function getActivityCalendar(Carbon $endDate, int $weeks = 53): array
    {
        $end = $endDate->copy()->endOfDay();
        $start = $end->copy()->startOfDay()->subWeeks($weeks - 1)->startOfWeek(CarbonInterface::MONDAY);

        $counts = AuditLog::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->pluck('count', 'date')
            ->map(fn ($count) => (int) $count)
            ->toArray();

        $days = [];
        $longestStreak = 0;
        $currentStreak = 0;
        $streak = 0;

        for ($day = $start->copy(); $day <= $end; $day->addDay()) {
            $date = $day->format('Y-m-d');
            $count = $counts[$date] ?? 0;
            $days[$date] = $count;

            $streak = $count > 0 ? $streak + 1 : 0;
            $longestStreak = max($longestStreak, $streak);
            $currentStreak = $streak;
        }

        $topContributors = AuditLog::selectRaw('owner_name, COUNT(*) as count')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('owner_name')
            ->groupBy('owner_name')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['name' => $row->owner_name, 'count' => (int) $row->count]);

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'days' => $days,
            'total' => array_sum($days),
            'max' => $days === [] ? 0 : max($days),
            'active_days' => count(array_filter($days)),
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'top_contributors' => $topContributors,
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

        $contentTrend = $this->getTrendData(Content::class, 'created_at', $periods, $periodType);
        $editingTrend = $this->getTrendData(ContentVersion::class, 'created_at', $periods, $periodType);
        $publishingTrend = $this->getTrendData(ContentVersion::class, 'published_at', $periods, $periodType);
        $assetTrend = $this->getAssetTrendData(Asset::class, 'created_at', $periods, $periodType);
        $redirectTrend = $this->getTrendData(Redirect::class, 'created_at', $periods, $periodType);
        $dataEntryTrend = $this->getTrendData(DataEntry::class, 'created_at', $periods, $periodType);
        $apiTrend = $this->getApiUsageTrend($space, $periods, $periodType);
        $trafficTrend = $this->getTrafficUsageTrend($space, $periods, $periodType);

        return [
            'periods' => $periods,
            'content_creation' => $contentTrend,
            'content_editing' => $editingTrend,
            'content_publishing' => $publishingTrend,
            'asset_uploads' => $assetTrend,
            'redirect_creation' => $redirectTrend,
            'data_entry_creation' => $dataEntryTrend,
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
     * Get trend data for a model's date field
     */
    private function getTrendData(string $model, string $dateField, array $periods, PeriodType $periodType): array
    {
        $data = $model::selectRaw("DATE_FORMAT($dateField, ?) as period, COUNT(*) as count", [$periodType->toMysqlDateFormat()])
            ->whereNotNull($dateField)
            ->groupBy('period')
            ->pluck('count', 'period')
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($periodType->toCarbonFormat());
            $result[$period] = $data[$formattedPeriod] ?? 0;
        }

        return $result;
    }

    private function getAssetTrendData(string $model, string $dateField, array $periods, PeriodType $periodType): array
    {
        $data = $model::selectRaw("DATE_FORMAT($dateField, ?) as period, COUNT(*) as count, SUM(size) as size", [$periodType->toMysqlDateFormat()])
            ->whereNotNull($dateField)
            ->groupBy('period')
            ->get('count', 'size', 'period')
            ->mapWithKeys(function ($item) {
                return [
                    $item->period => [
                        'count' => (int) $item->count,
                        'total_size' => (int) $item->size,
                    ],
                ];
            })
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($periodType->toCarbonFormat());
            $result[$period] = $data[$formattedPeriod] ?? [
                'count' => 0,
                'total_size' => 0,
            ];
        }

        return $result;
    }

    /**
     * Get API usage trend for a space
     */
    private function getApiUsageTrend(Space $space, array $periods, PeriodType $periodType): array
    {
        $data = SpaceApiHitHourly::where('space_id', $space->id)
            ->selectRaw('DATE_FORMAT(hour_timestamp, ?) as period, SUM(hit_count) as count', [$periodType->toMysqlDateFormat()])
            ->groupBy('period')
            ->pluck('count', 'period')
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($periodType->toCarbonFormat());
            $result[$period] = (int) ($data[$formattedPeriod] ?? 0);
        }

        return $result;
    }

    /**
     * Get traffic usage trend from SpaceTrafficUsageHourly
     */
    private function getTrafficUsageTrend(Space $space, array $periods, PeriodType $periodType): array
    {
        $data = SpaceTrafficUsageHourly::where('space_id', $space->id)
            ->selectRaw(
                'DATE_FORMAT(hour_timestamp, ?) as period,
                SUM(total_bytes) as total_bytes,
                SUM(request_count) as request_count',
                [$periodType->toMysqlDateFormat()]
            )
            ->groupBy('period')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item->period => [
                        'total_bytes' => (int) $item->total_bytes,
                        'request_count' => (int) $item->request_count,
                    ],
                ];
            })
            ->toArray();

        $result = [];
        foreach ($periods as $period) {
            $formattedPeriod = Carbon::parse($period)->format($periodType->toCarbonFormat());
            $result[$period] = $data[$formattedPeriod] ?? [
                'total_bytes' => 0,
                'request_count' => 0,
            ];
        }

        return $result;
    }

    /**
     * Categorize asset type based on MIME type
     */
    private function categorizeAssetType(?string $mimeType): string
    {
        if (! $mimeType) {
            return 'unknown';
        }

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'application/pdf') => 'document',
            str_starts_with($mimeType, 'application/msword') => 'document',
            str_starts_with($mimeType, 'application/vnd.openxmlformats') => 'document',
            str_starts_with($mimeType, 'application/vnd.ms-') => 'document',
            str_starts_with($mimeType, 'text/') => 'text',
            str_starts_with($mimeType, 'application/json') => 'data',
            str_starts_with($mimeType, 'application/xml') => 'data',
            str_starts_with($mimeType, 'application/zip') => 'archive',
            str_starts_with($mimeType, 'application/x-') => 'archive',
            default => 'other'
        };
    }
}
