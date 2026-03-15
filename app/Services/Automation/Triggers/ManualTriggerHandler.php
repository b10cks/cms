<?php

namespace App\Services\Automation\Triggers;

use App\Models\Management\Automation;
use App\Services\Automation\Enums\TriggerType;
use App\Services\Automation\TriggerCatalog;

class ManualTriggerHandler extends BaseTriggerHandler
{
    public function __construct(TriggerCatalog $triggerCatalog)
    {
        parent::__construct($triggerCatalog);
        $this->type = TriggerType::MANUAL;
    }

    public function evaluate(Automation $automation, array $context = []): bool
    {
        return $this->matchesAutomation($automation, $context);
    }
}
