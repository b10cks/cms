<?php

namespace App\Http\Controllers\Mgmt;

use App\Enums\PeriodType;
use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Services\Space\SpaceStatsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SpaceStatsController extends Controller
{
    protected SpaceStatsService $statsService;

    public function __construct(SpaceStatsService $statsService)
    {
        $this->statsService = $statsService;
    }
    /**
     * Get dashboard statistics for a space
     */
    public function __invoke(Space $space, Request $request): JsonResponse
    {
        $this->authorize('view', $space);

        // Parse request parameters
        $periodType = $this->getPeriodType($request->input('period', 'daily'));
        $startDate = $this->parseDate($request->input('start_date'), Carbon::now()->subDays(30));
        $endDate = $this->parseDate($request->input('end_date'), Carbon::now());

        $stats = $this->statsService->getDashboardStats($space, [
            'period_type' => $periodType,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return response()->json($stats);
    }

    /**
     * Get content-specific statistics
     */
    public function content(Space $space, Request $request): JsonResponse
    {
        $this->authorize('view', $space);

        $startDate = $this->parseDate($request->input('start_date'), Carbon::now()->subDays(30));
        $endDate = $this->parseDate($request->input('end_date'), Carbon::now());

        $stats = $this->statsService->getContentStats($space, $startDate, $endDate);

        return response()->json($stats);
    }

    /**
     * Get user activity statistics
     */
    public function userActivity(Space $space, Request $request): JsonResponse
    {
        $this->authorize('view', $space);

        $startDate = $this->parseDate($request->input('start_date'), Carbon::now()->subDays(30));
        $endDate = $this->parseDate($request->input('end_date'), Carbon::now());

        $stats = $this->statsService->getUserActivityStats($space, $startDate, $endDate);

        return response()->json($stats);
    }

    /**
     * Get system performance statistics
     */
    public function system(Space $space, Request $request): JsonResponse
    {
        $this->authorize('view', $space);

        $startDate = $this->parseDate($request->input('start_date'), Carbon::now()->subDays(30));
        $endDate = $this->parseDate($request->input('end_date'), Carbon::now());

        $stats = $this->statsService->getSystemStats($space, $startDate, $endDate);

        return response()->json($stats);
    }

    /**
     * Get trend statistics over time
     */
    public function trends(Space $space, Request $request): JsonResponse
    {
        $this->authorize('view', $space);

        $periodType = $this->getPeriodType($request->input('period', 'daily'));
        $startDate = $this->parseDate($request->input('start_date'), Carbon::now()->subDays(30));
        $endDate = $this->parseDate($request->input('end_date'), Carbon::now());

        $stats = $this->statsService->getTrendStats($space, $periodType, $startDate, $endDate);

        return response()->json($stats);
    }

    /**
     * Helper method to parse date from request
     */
    private function parseDate(?string $date, Carbon $default): Carbon
    {
        if (empty($date)) {
            return $default;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Helper method to get period type from string
     */
    private function getPeriodType(string $period): PeriodType
    {
        return match (strtolower($period)) {
            'daily' => PeriodType::DAILY,
            'weekly' => PeriodType::WEEKLY,
            'monthly' => PeriodType::MONTHLY,
            'yearly' => PeriodType::YEARLY,
            default => PeriodType::DAILY,
        };
    }
}
