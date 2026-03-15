<?php

namespace App\Services\Automation\Triggers;

use App\Models\Management\Automation;
use App\Services\Automation\Enums\TriggerType;
use App\Services\Automation\TriggerCatalog;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;

class TimeBasedTriggerHandler extends BaseTriggerHandler
{
    public function __construct(TriggerCatalog $triggerCatalog)
    {
        parent::__construct($triggerCatalog);
        $this->type = TriggerType::TIME_BASED;
    }

    public function evaluate(Automation $automation, array $context = []): bool
    {
        if (! $this->matchesAutomation($automation, $context)) {
            return false;
        }

        $schedule = trim((string) data_get($automation->trigger_config, 'schedule', ''));
        if ($schedule === '') {
            return false;
        }

        $timezone = data_get($automation->trigger_config, 'timezone', config('app.timezone'));

        try {
            return CronExpression::factory($schedule)->isDue(now(), $timezone);
        } catch (\Throwable $e) {
            Log::warning('Skipping automation with invalid cron expression.', [
                'automation_id' => $automation->id,
                'schedule' => $schedule,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
