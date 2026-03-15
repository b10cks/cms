<?php

namespace App\Http\Controllers\Mgmt\AutomationStats;

use App\Http\Resources\Management\AutomationExecutionResource;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AutomationStatsExecutionController extends BaseAutomationStatsController
{
    public function __invoke(
        Request $request,
        Space $space,
        Automation $automation
    ): AnonymousResourceCollection {
        $this->authorize('view', [$automation, $space]);

        $limit = min($request->input('limit', 50), 100);

        $executions = $this->usageService->getRecentExecutions($automation, $limit);

        return AutomationExecutionResource::collection($executions);
    }
}
