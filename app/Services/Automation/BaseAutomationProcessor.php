<?php

namespace App\Services\Automation;

use App\Models\Management\Automation;
use App\Models\Management\AutomationExecution;
use App\Support\SpaceContext;
use App\Services\Automation\Contracts\AutomationEngine as AutomationEngineInterface;
use App\Services\Automation\Enums\ActionType;
use InvalidArgumentException;

class BaseAutomationProcessor
{
    protected ?Automation $automation = null;

    protected ?AutomationExecution $currentExecution = null;

    public function __construct(
        protected AutomationEngineInterface $engine,
        protected AutomationUsageService $usageService
    ) {}

    public function process(string $automationId, array $context = []): void
    {
        $restoreSpace = null;

        try {
            $this->automation = Automation::query()
                ->with(['action', 'space'])
                ->findOrFail($automationId);

            if ($this->automation->space) {
                $restoreSpace = SpaceContext::enter($this->automation->space);
            }

            $executionId = $context['execution_id'] ?? null;
            unset($context['execution_id']);

            $this->currentExecution = $executionId
                ? AutomationExecution::query()
                    ->where('automation_id', $this->automation->id)
                    ->findOrFail($executionId)
                : null;

            if (! $this->automation->is_active) {
                throw new \RuntimeException('This automation is disabled.');
            }

            $existingContext = $this->currentExecution?->context ?? $context;
            $hasActionSnapshot = is_array(data_get($existingContext, 'execution_snapshot.action'));

            if (! $this->automation->action && ! $hasActionSnapshot) {
                throw new \RuntimeException('The linked automation action is missing.');
            }

            if ($this->automation->action && ! $this->automation->action->is_active) {
                throw new \RuntimeException('The linked action is disabled.');
            }

            if (! $this->usageService->canExecute($this->automation)) {
                throw new \RuntimeException('Automation execution limit reached');
            }

            $this->currentExecution = $this->usageService->startExecution(
                $this->automation,
                $context,
                $this->currentExecution,
            );

            $executionContext = $this->currentExecution->context ?? $context;
            $actionDefinition = $this->resolveActionDefinition($executionContext);
            $handler = $this->engine->getActionHandler($actionDefinition['type']);
            if (! $handler) {
                throw new InvalidArgumentException("No handler found for action type: {$actionDefinition['type']->value}");
            }

            $templateContext = $this->makeTemplateContext($executionContext);
            $result = $handler->execute($actionDefinition['config'], $templateContext);
            $this->usageService->completeExecution($this->currentExecution, is_array($result) ? $result : [
                'result' => $result,
            ]);

            if ($this->automation->action) {
                $this->automation->action->forceFill([
                    'last_executed_at' => now(),
                    'last_execution_status' => 'completed',
                    'last_execution_error' => null,
                ])->save();
            }
        } catch (\Throwable $e) {
            if ($this->currentExecution) {
                if ($this->currentExecution->started_at) {
                    $this->usageService->failExecution($this->currentExecution, $e);
                } else {
                    $this->usageService->abortExecution($this->currentExecution, $e);
                }
            }

            if ($this->automation?->action) {
                $this->automation->action->forceFill([
                    'last_executed_at' => now(),
                    'last_execution_status' => 'failed',
                    'last_execution_error' => $e->getMessage(),
                ])->save();
            }

            \Log::error('Failed to process automation', [
                'automation_id' => $automationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            if ($restoreSpace !== null) {
                $restoreSpace();
            }
        }
    }

    protected function makeTemplateContext(array $context): array
    {
        $automationSnapshot = data_get($context, 'execution_snapshot.automation', []);
        $actionSnapshot = data_get($context, 'execution_snapshot.action', []);
        $spaceSnapshot = data_get($context, 'execution_snapshot.space', []);

        return array_replace_recursive($context, [
            'automation' => [
                'id' => data_get($automationSnapshot, 'id', $this->automation?->id),
                'name' => data_get($automationSnapshot, 'name', $this->automation?->name),
                'description' => data_get($automationSnapshot, 'description', $this->automation?->description),
                'execution_count' => data_get($automationSnapshot, 'execution_count', $this->automation?->execution_count),
                'execution_limit' => data_get($automationSnapshot, 'execution_limit', $this->automation?->execution_limit),
            ],
            'space' => [
                'id' => data_get($spaceSnapshot, 'id', $this->automation?->space?->id),
                'name' => data_get($spaceSnapshot, 'name', $this->automation?->space?->name),
            ],
            'action' => [
                'id' => data_get($actionSnapshot, 'id', $this->automation?->action?->id),
                'name' => data_get($actionSnapshot, 'name', $this->automation?->action?->name),
                'type' => data_get($actionSnapshot, 'type', $this->automation?->action?->type?->value),
            ],
            'secret' => $this->automation?->action?->secrets ?? [],
        ]);
    }

    /**
     * @return array{type: ActionType, config: array<string, mixed>}
     */
    protected function resolveActionDefinition(array $context): array
    {
        $snapshotType = data_get($context, 'execution_snapshot.action.type');
        $snapshotConfig = data_get($context, 'execution_snapshot.action.config');

        $type = $snapshotType
            ? ActionType::from($snapshotType)
            : $this->automation?->action?->type;

        if (! $type) {
            throw new \RuntimeException('The linked automation action type is missing.');
        }

        return [
            'type' => $type,
            'config' => is_array($snapshotConfig)
                ? $snapshotConfig
                : ($this->automation?->action?->config ?? []),
        ];
    }

    public function cleanup(): void
    {
        $this->automation = null;
        $this->currentExecution = null;
    }
}
