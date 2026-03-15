<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AutomationResource;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use App\Services\Automation\AutomationContextFactory;
use App\Services\Automation\AutomationDispatcher;
use App\Services\Automation\AutomationUsageService;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AutomationTriggerController extends Controller
{
    public function __invoke(
        Request $request,
        Space $space,
        Automation $automation,
        AutomationUsageService $usageService,
        AutomationContextFactory $contextFactory,
        AutomationDispatcher $dispatcher,
    ): AutomationResource {
        $this->authorize('update', [$automation, $space]);
        $automation->loadMissing('action');

        if ($automation->trigger_type !== TriggerType::MANUAL) {
            throw ValidationException::withMessages([
                'automation' => ['Only manual automations can be triggered directly.'],
            ]);
        }

        if (! $automation->is_active) {
            throw ValidationException::withMessages([
                'automation' => ['This automation is disabled.'],
            ]);
        }

        if (! $automation->action || ! $automation->action->is_active) {
            throw ValidationException::withMessages([
                'action' => ['The linked action is disabled.'],
            ]);
        }

        if (! $usageService->canExecute($automation)) {
            throw ValidationException::withMessages([
                'automation' => ['This automation has reached its execution limit.'],
            ]);
        }

        $request->validate([
            'payload' => ['nullable', 'array'],
        ]);

        $dispatchContext = $contextFactory->forAutomation(
            $automation,
            (array) $request->input('payload', []),
            [
                'triggered_by' => $request->user()?->id,
                'source' => 'manual',
            ],
        );

        $dispatcher->dispatch($automation, $dispatchContext);

        return new AutomationResource($automation->load('action'));
    }
}
