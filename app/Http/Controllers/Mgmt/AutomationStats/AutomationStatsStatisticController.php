<?php

namespace App\Http\Controllers\Mgmt\AutomationStats;

use App\Http\Resources\Management\AutomationUsageStatsResource;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationStatsStatisticController extends BaseAutomationStatsController
{
    public function __invoke(
        Request $request,
        Space $space,
        Automation $automation
    ): JsonResponse {
        $this->authorize('view', [$automation, $space]);

        $periodType = $request->input('period_type', 'daily');
        if (!\in_array($periodType, ['daily', 'weekly', 'monthly'])) {
            $periodType = 'daily';
        }

        $startDate = $request->has('start_date')
            ? Carbon::parse($request->input('start_date'))
            : null;

        $endDate = $request->has('end_date')
            ? Carbon::parse($request->input('end_date'))
            : null;

        $stats = $this->usageService->getStatistics($automation, $periodType, $startDate, $endDate);

        // Add remaining executions info if there's a limit
        $remainingExecutions = $automation->execution_limit
            ? $automation->execution_limit - $automation->execution_count
            : null;

        return response()->json([
            'data' => [
                'execution_count' => $automation->execution_count,
                'execution_limit' => $automation->execution_limit,
                'remaining_executions' => $remainingExecutions,
                'statistics' => AutomationUsageStatsResource::collection($stats),
            ]
        ]);
    }
}
