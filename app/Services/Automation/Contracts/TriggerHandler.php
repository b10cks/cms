<?php

namespace App\Services\Automation\Contracts;

use App\Models\Management\Automation;
use App\Services\Automation\Enums\TriggerType;

interface TriggerHandler
{
    public function canHandle(TriggerType $triggerType): bool;

    public function initialize(): void;

    public function evaluate(Automation $automation, array $context = []): bool;
}
