<?php

namespace App\Services\Automation;

use App\Models\Management\Automation;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AutomationContextFactory
{
    public function __construct(
        private readonly CurrentSpaceResolver $currentSpaceResolver,
        private readonly TriggerCatalog $triggerCatalog,
    ) {}

    public function forAutomation(Automation $automation, array $context = [], array $meta = []): array
    {
        $configuredPayload = data_get($automation->trigger_config, 'payload', []);
        $mergedContext = array_replace_recursive(
            is_array($configuredPayload) ? $configuredPayload : [],
            $context,
        );

        return array_replace_recursive($mergedContext, [
            'trigger' => $automation->trigger?->toArray(),
            'triggered_at' => now()->toIso8601String(),
            ...$meta,
        ]);
    }

    public function forModelEvent(
        Model $model,
        TriggerType $triggerType,
        ?array $before = null,
        ?array $after = null,
        array $changedColumns = [],
        ?Space $space = null,
    ): array {
        $space ??= $this->currentSpaceResolver->resolve();
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $record = $after ?? $before ?? [];
        $changes = [];

        foreach ($changedColumns as $column) {
            $changes[$column] = [
                'before' => $before[$column] ?? null,
                'after' => $after[$column] ?? null,
            ];
        }

        $cache = $model instanceof Content ? [
            'ttl' => $model->settings?->cacheTtl(),
            'tags' => $model->settings?->cacheTags() ?? [],
        ] : null;

        return [
            'source' => 'trigger',
            'operation' => $triggerType->value,
            // First-class cache keys for content models: `record.settings` is a
            // serialized JSON string in snapshots, so webhook templates could not
            // reach the tags through it.
            ...($cache !== null ? ['cache' => $cache, 'cache_tags' => $cache['tags']] : []),
            'table' => $table,
            'resource' => $table,
            'entity' => $table,
            'model' => class_basename($model),
            'model_type' => $table,
            'record_id' => $model->getKey(),
            'record' => $record,
            'previous' => $before,
            'changes' => $changes,
            'changed_fields' => array_values($changedColumns),
            'space' => $space ? [
                'id' => $space->id,
                'name' => $space->name,
            ] : null,
            'actor' => [
                'id' => auth()->id(),
            ],
            'meta' => [
                'table_label' => $this->triggerCatalog->labelForTable($table),
                'primary_key' => $primaryKey,
                'primary_value' => $record[$primaryKey] ?? $model->getKey(),
                'deleted' => $triggerType === TriggerType::ON_DELETE,
                'changed_count' => count($changedColumns),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function changedColumnsForUpdate(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];

        $columns = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $column) {
            if ($before[$column] ?? $after[$column] !== null ?? null) {
                $columns[] = $column;
            }
        }

        sort($columns);

        return $columns;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function normalizeSnapshot(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return Arr::map($snapshot, fn (mixed $value): mixed => $value);
    }
}
