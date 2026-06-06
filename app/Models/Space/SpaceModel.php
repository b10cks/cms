<?php

namespace App\Models\Space;

use App\Services\Automation\AutomationContextFactory;
use App\Services\Automation\Contracts\AutomationEngine;
use App\Services\Automation\CurrentSpaceResolver;
use App\Services\Automation\Enums\TriggerType;
use App\Services\Automation\TriggerCatalog;
use Illuminate\Database\Eloquent\Model;

abstract class SpaceModel extends Model
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $automationBeforeSnapshot = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $automationAfterSnapshot = null;

    /**
     * @var array<int, string>
     */
    protected array $automationChangedColumns = [];

    public function getConnection()
    {
        return app('App\Services\Database\SpaceModelResolver')->getDefaultConnection();
    }

    public function getConnectionName()
    {
        return app('App\Services\Database\SpaceModelResolver')->getConnectionName();
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            $model->automationBeforeSnapshot = $model->getOriginal();
        });

        static::deleting(function (self $model): void {
            $model->automationBeforeSnapshot = $model->attributesToArray();
        });

        static::created(function (self $model): void {
            $model->automationAfterSnapshot = $model->attributesToArray();
            $model->dispatchAutomationTrigger(TriggerType::ON_INSERT);
        });

        static::updated(function (self $model): void {
            $model->automationAfterSnapshot = $model->attributesToArray();
            $model->automationChangedColumns = array_values(array_filter(
                array_keys($model->getChanges()),
                fn (string $column): bool => $column !== 'updated_at',
            ));
            $model->dispatchAutomationTrigger(TriggerType::ON_UPDATE);
        });

        static::deleted(function (self $model): void {
            $model->dispatchAutomationTrigger(TriggerType::ON_DELETE);
        });
    }

    protected function dispatchAutomationTrigger(TriggerType $triggerType): void
    {
        $space = app(CurrentSpaceResolver::class)->resolve();

        if (! $space) {
            return;
        }

        if (! $triggerType->isContentLifecycle() && ! app(TriggerCatalog::class)->supportsTable($this->getTable())) {
            return;
        }

        $connection = $this->getConnection();
        $before = $this->automationBeforeSnapshot;
        $after = $triggerType === TriggerType::ON_DELETE
            ? null
            : ($this->automationAfterSnapshot ?? $this->attributesToArray());

        $dispatch = function () use ($triggerType, $before, $after, $space): void {
            /** @var AutomationContextFactory $contextFactory */
            $contextFactory = app(AutomationContextFactory::class);

            app(AutomationEngine::class)->processTrigger(
                $triggerType,
                $contextFactory->forModelEvent(
                    $this,
                    $triggerType,
                    $contextFactory->normalizeSnapshot($before),
                    $contextFactory->normalizeSnapshot($after),
                    $triggerType === TriggerType::ON_UPDATE ? $this->automationChangedColumns : [],
                    $space,
                ),
            );
        };

        if (method_exists($connection, 'afterCommit')) {
            $connection->afterCommit(function () use ($dispatch): void {
                try {
                    $dispatch();
                } catch (\Throwable $exception) {
                    \Log::warning('Failed to dispatch automation trigger after a space model event.', [
                        'table' => $this->getTable(),
                        'model_id' => $this->getKey(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

            return;
        }

        try {
            $dispatch();
        } catch (\Throwable $exception) {
            \Log::warning('Failed to dispatch automation trigger after a space model event.', [
                'table' => $this->getTable(),
                'model_id' => $this->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
