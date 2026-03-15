<?php

namespace App\Console\Commands;

use App\Services\Automation\Contracts\AutomationEngine;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Console\Command;

class RunScheduledAutomationsCommand extends Command
{
    protected $signature = 'automations:run-scheduled';

    protected $description = 'Evaluate and dispatch due scheduled automations.';

    public function handle(AutomationEngine $engine): int
    {
        $engine->processTrigger(TriggerType::TIME_BASED, [
            'source' => 'schedule',
        ]);

        $this->info('Scheduled automations evaluated.');

        return self::SUCCESS;
    }
}
