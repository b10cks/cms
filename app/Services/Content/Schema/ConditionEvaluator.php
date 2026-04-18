<?php

namespace App\Services\Content\Schema;

use Carbon\Carbon;

class ConditionEvaluator
{
    public const array OPERATORS = [
        'equals',
        'not_equals',
        'in',
        'not_in',
        'is_empty',
        'is_not_empty',
        'gt',
        'gte',
        'lt',
        'lte',
        'contains',
    ];

    public function isVisible(
        SchemaField $field,
        BlockSchema $scopeSchema,
        array $localScope,
        array $effectiveScope,
    ): bool {
        return $this->evaluate($field, $scopeSchema, $localScope, $effectiveScope);
    }

    public function evaluate(
        SchemaField $field,
        BlockSchema $scopeSchema,
        array $localScope,
        array $effectiveScope,
    ): bool {
        $conditions = $field->getConditions();

        if ($conditions === null || ($conditions['rules'] ?? []) === []) {
            return true;
        }

        $mode = $conditions['mode'] ?? 'all';
        $results = array_map(
            fn (array $rule): bool => $this->evaluateRule($rule, $scopeSchema, $localScope, $effectiveScope),
            $conditions['rules']
        );

        return $mode === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    protected function evaluateRule(array $rule, BlockSchema $scopeSchema, array $localScope, array $effectiveScope): bool
    {
        $controllerField = $scopeSchema->getField((string) ($rule['field'] ?? ''));
        $operator = (string) ($rule['operator'] ?? 'equals');

        $localValue = data_get($localScope, $rule['field']);
        $value = $controllerField?->isTranslatable()
            || ($localValue === null && data_get($effectiveScope, $rule['field']) !== null)
            ? data_get($effectiveScope, $rule['field'])
            : $localValue;

        $expected = $rule['value'] ?? null;

        return match ($operator) {
            'equals' => $value == $expected,
            'not_equals' => $value != $expected,
            'in' => in_array($value, (array) $expected, true),
            'not_in' => ! in_array($value, (array) $expected, true),
            'is_empty' => $this->isEmpty($value),
            'is_not_empty' => ! $this->isEmpty($value),
            'gt' => $this->compare($value, $expected) > 0,
            'gte' => $this->compare($value, $expected) >= 0,
            'lt' => $this->compare($value, $expected) < 0,
            'lte' => $this->compare($value, $expected) <= 0,
            'contains' => $this->contains($value, $expected),
            default => true,
        };
    }

    protected function contains(mixed $value, mixed $expected): bool
    {
        if (is_array($value)) {
            return in_array($expected, $value, true);
        }

        if (is_string($value) && is_string($expected)) {
            return str_contains(mb_strtolower($value), mb_strtolower($expected));
        }

        return false;
    }

    protected function compare(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return $left <=> $right;
        }

        if (is_string($left) && is_string($right)) {
            $leftDate = $this->toTimestamp($left);
            $rightDate = $this->toTimestamp($right);

            if ($leftDate !== null && $rightDate !== null) {
                return $leftDate <=> $rightDate;
            }

            return strcasecmp($left, $right);
        }

        return $left <=> $right;
    }

    protected function toTimestamp(string $value): ?int
    {
        try {
            return Carbon::parse($value)->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
