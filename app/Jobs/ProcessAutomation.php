<?php

namespace App\Jobs;

use App\Events\Automations\AutomationCompleted;
use App\Events\Automations\AutomationFailed;
use App\Models\Management\Automation;
use App\Services\Automation\BaseAutomationProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutomation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        protected string $automationId,
        protected array  $context = [],
        protected ?string $executionId = null,
    )
    {
    }

    public function handle(BaseAutomationProcessor $processor): void
    {
        try {
            $processor->process($this->automationId, [
                ...$this->context,
                'execution_id' => $this->executionId,
            ]);
            event(new AutomationCompleted(
                Automation::findOrFail($this->automationId),
                $this->context
            ));

        } catch (\Throwable $e) {
            event(new AutomationFailed(
                Automation::findOrFail($this->automationId),
                $e,
                $this->context
            ));

            throw $e;
        } finally {
            $processor->cleanup();
        }
    }
}
