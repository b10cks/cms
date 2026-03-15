<?php

namespace App\Services\Automation;

use App\Events\Automations\AutomationTriggered;
use App\Jobs\ProcessAutomation;
use App\Models\Management\Automation;
use App\Models\Management\AutomationExecution;

class AutomationDispatcher
{
    public function __construct(
        private readonly AutomationUsageService $usageService,
    ) {}

    public function dispatch(Automation $automation, array $context = []): AutomationExecution
    {
        $automation->forceFill([
            'last_triggered_at' => now(),
        ])->save();

        $execution = $this->usageService->queueExecution($automation, $context);

        ProcessAutomation::dispatch($automation->id, $context, $execution->id)
            ->afterCommit();

        event(new AutomationTriggered($automation, $context));

        return $execution;
    }
}
