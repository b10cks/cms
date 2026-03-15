<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AutomationExecutionResource;
use App\Models\Management\AutomationExecution;
use App\Models\Management\Space;
use App\Services\Automation\AutomationContextFactory;
use App\Services\Automation\AutomationDispatcher;
use App\Services\Automation\AutomationUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AutomationExecutionReplayController extends Controller
{
    public function __invoke(
        Request $request,
        Space $space,
        AutomationExecution $automationExecution,
        AutomationUsageService $usageService,
        AutomationContextFactory $contextFactory,
        AutomationDispatcher $dispatcher,
    ): JsonResponse {
        $automationExecution->loadMissing('automation.action');

        $automation = $automationExecution->automation;

        if (! $automation || $automation->space_id !== $space->id) {
            abort(404);
        }

        $this->authorize('update', [$automation, $space]);

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

        $dispatchContext = $contextFactory->forAutomation(
            $automation,
            $automationExecution->context ?? [],
            [
                'triggered_at' => now()->toIso8601String(),
                'triggered_by' => $request->user()?->id,
                'source' => 'replay',
                'replayed_from_execution_id' => $automationExecution->id,
            ],
        );

        $queuedExecution = $dispatcher->dispatch($automation, $dispatchContext);

        return (new AutomationExecutionResource($queuedExecution->load('automation.action')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
