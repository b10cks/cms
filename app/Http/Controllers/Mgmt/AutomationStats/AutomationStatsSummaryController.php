<?php

namespace App\Http\Controllers\Mgmt\AutomationStats;

use App\Http\Resources\Management\AutomationExecutionResource;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use Illuminate\Http\JsonResponse;

class AutomationStatsSummaryController extends BaseAutomationStatsController
{
    public function __invoke(
        Space $space,
        Automation $automation
    ): JsonResponse {
        $this->authorize('view', [$automation, $space]);

        // Get stats for the last 24 hours
        $recentStats = $this->usageService->getStatistics(
            $automation,
            'daily',
            now()->subDay(),
            now()
        );

        $lastExecution = $this->usageService->getRecentExecutions($automation, 1)[0] ?? null;

        return response()->json([
            'data' => [
                'execution_count' => $automation->execution_count,
                'execution_limit' => $automation->execution_limit,
                'remaining_executions' => $automation->execution_limit
                    ? $automation->execution_limit - $automation->execution_count
                    : null,
                'last_24h' => [
                    'total_executions' => collect($recentStats)->sum('total_executions'),
                    'successful_executions' => collect($recentStats)->sum('successful_executions'),
                    'failed_executions' => collect($recentStats)->sum('failed_executions'),
                ],
                'last_execution' => $lastExecution
                    ? new AutomationExecutionResource($lastExecution)
                    : null,
            ]
        ]);
    }
}
