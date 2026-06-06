<?php

namespace App\Services\Automation\Triggers;

use App\Models\Management\Automation;
use App\Services\Automation\Enums\TriggerType;

class ContentPublishedTriggerHandler extends BaseTriggerHandler
{
    protected TriggerType $type = TriggerType::CONTENT_PUBLISHED;

    public function evaluate(Automation $automation, array $context = []): bool
    {
        return $this->matchesConditions($automation, $context);
    }
}
