<?php

namespace App\Http\Controllers\Mgmt\AutomationStats;

use App\Enums\PeriodType;
use App\Http\Resources\Management\AutomationUsageStatsResource;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AutomationStatsTrendController extends BaseAutomationStatsController
{
    public function __invoke(
        Request $request,
        Space $space,
        Automation $automation
    ): AnonymousResourceCollection {
        $this->authorize('view', [$automation, $space]);

        $periodType = PeriodType::tryFrom($request->input('period_type', 'daily'));
        if (!$periodType) {
            abort(422, 'Invalid period type');
        }
        $periods = min($request->input('periods', 30), 90);

        $trends = $this->usageService->getExecutionTrends($automation, $periodType, $periods);

        return AutomationUsageStatsResource::collection($trends);
    }
}
