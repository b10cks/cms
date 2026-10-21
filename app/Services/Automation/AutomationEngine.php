<?php

namespace App\Services\Automation;

use App\Models\Management\Automation;
use App\Services\Automation\Contracts\ActionHandler;
use App\Services\Automation\Contracts\AutomationEngine as AutomationEngineInterface;
use App\Services\Automation\Contracts\TriggerHandler;
use App\Services\Automation\Enums\ActionType;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AutomationEngine implements AutomationEngineInterface
{
    /**
     * @var Collection<TriggerHandler>
     */
    protected Collection $triggerHandlers;

    /**
     * @var Collection<ActionHandler>
     */
    protected Collection $actionHandlers;

    public function __construct(
        protected AutomationUsageService $usageService,
        protected AutomationContextFactory $contextFactory,
        protected AutomationDispatcher $dispatcher,
        protected TriggerCatalog $triggerCatalog,
    ) {
        $this->triggerHandlers = collect();
        $this->actionHandlers = collect();
    }

    public function registerTriggerHandler(TriggerHandler $handler): void
    {
        $this->triggerHandlers->push($handler);
    }

    public function registerActionHandler(ActionHandler $handler): void
    {
        $this->actionHandlers->push($handler);
    }

    public function initialize(): void
    {
        $this->triggerHandlers->each(function (TriggerHandler $handler) {
            $handler->initialize();
        });
    }

    public static function activeTriggersCacheKey(string $spaceId): string
    {
        return "automation:active-triggers:{$spaceId}";
    }

    /**
     * Trigger types with at least one active automation in the space.
     * Cached in the shared store; Automation model events forget it.
     *
     * @return array<int, string>
     */
    public function activeTriggerTypes(string $spaceId): array
    {
        return Cache::remember(
            self::activeTriggersCacheKey($spaceId),
            now()->addHour(),
            fn (): array => Automation::query()
                ->where('space_id', $spaceId)
                ->where('is_active', true)
                ->distinct()
                ->pluck('trigger_type')
                ->map(fn (mixed $type): string => $type instanceof TriggerType ? $type->value : (string) $type)
                ->all(),
        );
    }

    public function processTrigger(TriggerType $triggerType, array $context = []): void
    {
        $spaceId = data_get($context, 'space.id') ?? data_get($context, 'space_id');

        if ($spaceId && ! \in_array($triggerType->value, $this->activeTriggerTypes($spaceId), true)) {
            return;
        }

        $automations = Automation::query()
            ->with('action')
            ->where('is_active', true)
            ->where('trigger_type', $triggerType->value)
            ->whereHas('action', fn ($query) => $query->where('is_active', true))
            ->when(data_get($context, 'space.id') ?? data_get($context, 'space_id'), function ($query, $spaceId) {
                $query->where('space_id', $spaceId);
            })
            ->when($this->triggerCatalog->resolveTableFromConfig($context), function ($query, $table) {
                $query->where(function ($nestedQuery) use ($table) {
                    $nestedQuery
                        ->where('trigger_config->table', $table)
                        ->orWhere('trigger_config->resource', $table)
                        ->orWhereNull('trigger_config->table');
                });
            })
            ->lazy();

        $handler = $this->getTriggerHandler($triggerType);
        if (! $handler) {
            \Log::warning("No handler found for trigger type: {$triggerType->value}");

            return;
        }

        foreach ($automations as $automation) {
            if ($handler->evaluate($automation, $context)) {
                if (! $this->usageService->canExecute($automation)) {
                    continue;
                }

                $dispatchContext = $this->contextFactory->forAutomation($automation, $context);
                $this->dispatcher->dispatch($automation, $dispatchContext);
            }
        }
    }

    public function getActionHandler(ActionType $actionType): ?ActionHandler
    {
        return $this->actionHandlers->first(function (ActionHandler $handler) use ($actionType) {
            return $handler->canHandle($actionType);
        });
    }

    protected function getTriggerHandler(TriggerType $triggerType): ?TriggerHandler
    {
        return $this->triggerHandlers->first(function (TriggerHandler $handler) use ($triggerType) {
            return $handler->canHandle($triggerType);
        });
    }
}
