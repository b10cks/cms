<?php

namespace App\Services\Automation\Contracts;

interface AutomationProcessor
{
    public function process(string $automationId, array $context = []): void;

    public function cleanup(): void;
}
