<?php

namespace App\Services\Automation\Contracts;

use App\Services\Automation\Enums\ActionType;
use App\Services\Automation\Enums\TriggerType;

interface AutomationEngine
{
    public function registerTriggerHandler(TriggerHandler $handler): void;

    public function registerActionHandler(ActionHandler $handler): void;

    public function initialize(): void;

    public function processTrigger(TriggerType $triggerType, array $context = []): void;

    public function getActionHandler(ActionType $actionType): ?ActionHandler;
}
