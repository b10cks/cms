<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Enums\ActionType;

class VoidActionHandler extends BaseActionHandler
{
    public function __construct()
    {
        $this->type = ActionType::VOID;
    }

    public function execute(array $config, array $context = []): mixed
    {
        $message = $config['message'] ?? null;
        if (\is_string($message) && trim($message) !== '') {
            \Log::info(trim($this->replaceVariables($message, $context)), [
                'automation' => data_get($context, 'automation.id'),
                'action' => data_get($context, 'action.id'),
            ]);
        }

        return null;
    }
}
