<?php

namespace App\Events\Automations;

use App\Models\Management\Automation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutomationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Automation $automation,
        public \Throwable $error,
        public array $context = [],
    ) {
    }
}
