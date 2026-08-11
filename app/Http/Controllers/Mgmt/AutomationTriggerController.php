<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\AutomationResource;
use App\Models\Management\Automation;
use App\Models\Management\Space;
use App\Models\Space\Content;
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
        $this->authorize('trigger', [$automation, $space]);
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
            'content_id' => ['nullable', 'string'],
        ]);

        $contentContext = [];
        if ($contentId = $request->input('content_id')) {
            $content = Content::query()->whereNull('deleted_at')->find($contentId);

            if (! $content) {
                throw ValidationException::withMessages([
                    'content_id' => ['This content does not exist.'],
                ]);
            }

            $blockIds = array_filter((array) data_get($automation->trigger_config, 'block_ids', []));
            if ($blockIds !== [] && ! in_array($content->block_id, $blockIds, true)) {
                throw ValidationException::withMessages([
                    'content_id' => ['This automation is not available for this content type.'],
                ]);
            }

            $contentContext = $contextFactory->forModelEvent(
                $content,
                TriggerType::MANUAL,
                null,
                $contextFactory->normalizeSnapshot($content->attributesToArray()),
                [],
                $space,
            );
        }

        $dispatchContext = $contextFactory->forAutomation(
            $automation,
            [...$contentContext, ...(array) $request->input('payload', [])],
            [
                'triggered_by' => $request->user()?->id,
                'source' => 'manual',
            ],
        );

        $dispatcher->dispatch($automation, $dispatchContext);

        return new AutomationResource($automation->load('action'));
    }
}
