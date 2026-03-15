<?php

namespace App\Services\Automation\Triggers;

use App\Models\Management\Automation;
use App\Services\Automation\Contracts\TriggerHandler;
use App\Services\Automation\Enums\TriggerType;
use App\Services\Automation\TriggerCatalog;

abstract class BaseTriggerHandler implements TriggerHandler
{
    protected TriggerType $type;

    public function __construct(
        protected readonly TriggerCatalog $triggerCatalog,
    ) {}

    public function canHandle(TriggerType $triggerType): bool
    {
        return $this->type->value === $triggerType->value;
    }

    public function initialize(): void
    {
        // Base implementation does nothing
    }

    protected function matchesAutomation(Automation $automation, array $context): bool
    {
        return $this->matchesResource($automation, $context)
            && $this->matchesWatchedColumns($automation, $context)
            && $this->matchesConditions($automation, $context);
    }

    protected function matchesResource(Automation $automation, array $context): bool
    {
        $table = $this->triggerCatalog->resolveTableFromConfig($automation->trigger_config);
        if ($table === null || $table === 'any') {
            return true;
        }

        $candidates = array_filter([
            data_get($context, 'table'),
            data_get($context, 'resource'),
            data_get($context, 'entity'),
            data_get($context, 'model'),
            data_get($context, 'model_type'),
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        foreach ($candidates as $candidate) {
            if (strcasecmp((string) $candidate, $table) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function matchesWatchedColumns(Automation $automation, array $context): bool
    {
        if ($automation->trigger_type !== TriggerType::ON_UPDATE) {
            return true;
        }

        $watchedColumns = array_values(array_filter(
            (array) data_get($automation->trigger_config, 'watch_columns', []),
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        if ($watchedColumns === []) {
            return true;
        }

        $changedFields = array_values(array_filter(
            (array) data_get($context, 'changed_fields', []),
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        return collect($watchedColumns)
            ->intersect($changedFields)
            ->isNotEmpty();
    }

    protected function matchesConditions(Automation $automation, array $context): bool
    {
        $conditions = data_get($automation->trigger_config, 'conditions', []);
        if (! is_array($conditions) || $conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->matchesCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    protected function matchesCondition(array $condition, array $context): bool
    {
        $path = (string) ($condition['path'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'eq');
        $expected = $condition['value'] ?? null;
        $actual = data_get($context, $path);

        return match ($operator) {
            'eq' => $this->normalizeComparable($actual) === $this->normalizeComparable($expected),
            'ne' => $this->normalizeComparable($actual) !== $this->normalizeComparable($expected),
            'contains' => $this->contains($actual, $expected),
            'gt' => $this->compare($actual, $expected, '>'),
            'gte' => $this->compare($actual, $expected, '>='),
            'lt' => $this->compare($actual, $expected, '<'),
            'lte' => $this->compare($actual, $expected, '<='),
            'in' => $this->inList($actual, $expected),
            'nin' => ! $this->inList($actual, $expected),
            'exists' => data_get($context, $path, '__missing__') !== '__missing__',
            'empty' => blank($actual),
            default => false,
        };
    }

    protected function normalizeComparable(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if (is_numeric($trimmed)) {
                return $trimmed + 0;
            }

            if (in_array(strtolower($trimmed), ['true', 'false'], true)) {
                return strtolower($trimmed) === 'true';
            }
        }

        return $value;
    }

    protected function compare(mixed $actual, mixed $expected, string $operator): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return match ($operator) {
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            default => false,
        };
    }

    protected function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, $actual, true);
        }

        if (is_string($actual)) {
            return str_contains($actual, (string) $expected);
        }

        return false;
    }

    protected function inList(mixed $actual, mixed $expected): bool
    {
        $haystack = is_array($expected)
            ? $expected
            : array_filter(array_map('trim', explode(',', (string) $expected)));

        return in_array($actual, $haystack, true);
    }
}
