<?php

namespace App\Http\Controllers\Mgmt\AutomationStats;

use App\Http\Controllers\Controller;
use App\Services\Automation\AutomationUsageService;

class BaseAutomationStatsController extends Controller
{
    public function __construct(protected AutomationUsageService $usageService)
    {
    }
}
