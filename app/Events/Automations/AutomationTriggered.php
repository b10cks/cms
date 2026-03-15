<?php

namespace App\Events\Automations;

use App\Models\Management\Automation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutomationTriggered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Automation $automation,
        public array $context = [],
    ) {
    }
}
