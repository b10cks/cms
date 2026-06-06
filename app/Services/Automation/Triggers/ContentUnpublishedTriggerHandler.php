<?php

namespace App\Services\Automation\Triggers;

use App\Models\Management\Automation;
use App\Services\Automation\Enums\TriggerType;

class ContentUnpublishedTriggerHandler extends BaseTriggerHandler
{
    protected TriggerType $type = TriggerType::CONTENT_UNPUBLISHED;

    public function evaluate(Automation $automation, array $context = []): bool
    {
        return $this->matchesConditions($automation, $context);
    }
}
