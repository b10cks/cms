<?php

namespace App\Services\Automation\Contracts;


use App\Services\Automation\Enums\ActionType;

interface ActionHandler
{
    public function canHandle(ActionType $actionType): bool;

    public function execute(array $config, array $context = []): mixed;
}
