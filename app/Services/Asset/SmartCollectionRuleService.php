<?php

namespace App\Services\Asset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Translates a smart asset collection's stored `rules` json into an Asset
 * query. Fields and operators are strictly whitelisted — user input is never
 * interpolated into SQL.
 *
 * Rules schema:
 * {
 *   "match": "all" | "any",
 *   "conditions": [ { "field": "...", "operator": "...", "value": ... } ]
 * }
 */
class SmartCollectionRuleService
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    /**
     * Whitelisted operators per supported field.
     *
     * @var array<string, array<int, string>>
     */
    private const FIELD_OPERATORS = [
        'filename' => ['contains', 'equals'],
        'extension' => ['equals', 'in'],
        'mime_type' => ['equals', 'in', 'prefix'],
        'size' => ['gt', 'gte', 'lt', 'lte'],
        'folder' => ['equals', 'null'],
        'tags' => ['any', 'all'],
        'rights_status' => ['equals'],
        'license_expires_at' => ['before', 'after'],
        'created_at' => ['before', 'after'],
        'updated_at' => ['before', 'after'],
        'orientation' => ['equals'],
        'untagged' => ['equals'],
    ];

    private const COMPARISON_OPERATORS = [
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'before' => '<',
        'after' => '>',
    ];

    private const ORIENTATIONS = ['landscape', 'portrait', 'square'];

    /**
     * Validate and normalize a rules payload.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array{match: string, conditions: array<int, array{field: string, operator: string, value: mixed}>}
     *
     * @throws ValidationException
     */
    public function validate(array $rules): array
    {
        $match = $rules['match'] ?? self::MATCH_ALL;

        if (! in_array($match, [self::MATCH_ALL, self::MATCH_ANY], true)) {
            $this->fail('rules.match', 'The match mode must be "all" or "any".');
        }

        $conditions = $rules['conditions'] ?? null;

        if (! is_array($conditions) || $conditions === []) {
            $this->fail('rules.conditions', 'At least one condition is required.');
        }

        $normalized = [];

        foreach (array_values($conditions) as $index => $condition) {
            if (! is_array($condition)) {
                $this->fail("rules.conditions.{$index}", 'Each condition must be an object.');
            }

            $field = $condition['field'] ?? null;

            if (! is_string($field) || ! array_key_exists($field, self::FIELD_OPERATORS)) {
                $this->fail("rules.conditions.{$index}.field", 'Unsupported field.');
            }

            $operator = $condition['operator'] ?? null;

            if (! is_string($operator) || ! in_array($operator, self::FIELD_OPERATORS[$field], true)) {
                $this->fail("rules.conditions.{$index}.operator", "Unsupported operator for field \"{$field}\".");
            }

            $normalized[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $this->validateValue($field, $operator, $condition['value'] ?? null, $index),
            ];
        }

        return [
            'match' => $match,
            'conditions' => $normalized,
        ];
    }

    /**
     * Apply the given rules to an Asset query builder.
     *
     * @param  array<array-key, mixed>  $rules
     *
     * @throws ValidationException
     */
    public function apply(Builder $query, array $rules): Builder
    {
        $rules = $this->validate($rules);
        $boolean = $rules['match'] === self::MATCH_ANY ? 'or' : 'and';

        return $query->where(function (Builder $group) use ($rules, $boolean) {
            foreach ($rules['conditions'] as $condition) {
                $group->where(
                    fn (Builder $q) => $this->applyCondition($q, $condition),
                    null,
                    null,
                    $boolean
                );
            }
        });
    }

    /**
     * @param  array{field: string, operator: string, value: mixed}  $condition
     */
    private function applyCondition(Builder $query, array $condition): void
    {
        $operator = $condition['operator'];
        $value = $condition['value'];

        match ($condition['field']) {
            'filename' => $operator === 'contains'
                ? $query->where('filename', 'LIKE', "%{$value}%")
                : $query->where('filename', $value),
            'extension' => $operator === 'in'
                ? $query->whereIn('extension', $value)
                : $query->where('extension', $value),
            'mime_type' => match ($operator) {
                'in' => $query->whereIn('mime_type', $value),
                'prefix' => $query->where('mime_type', 'LIKE', $value.'%'),
                default => $query->where('mime_type', $value),
            },
            'size' => $query->where('size', self::COMPARISON_OPERATORS[$operator], $value),
            'folder' => $operator === 'null' || $value === null
                ? $query->whereNull('folder_id')
                : $query->where('folder_id', $value),
            'tags' => $this->applyTagsCondition($query, $operator, $value),
            'rights_status' => $query->where('rights_status', $value),
            'license_expires_at' => $query
                ->whereNotNull('license_expires_at')
                ->where('license_expires_at', self::COMPARISON_OPERATORS[$operator], $value),
            'created_at', 'updated_at' => $query->where(
                $condition['field'],
                self::COMPARISON_OPERATORS[$operator],
                $value
            ),
            'orientation' => $this->applyOrientationCondition($query, $value),
            'untagged' => $this->applyUntaggedCondition($query, $value),
        };
    }

    /**
     * @param  array<int, string>  $tagIds
     */
    private function applyTagsCondition(Builder $query, string $operator, array $tagIds): void
    {
        if ($operator === 'all') {
            foreach ($tagIds as $tagId) {
                $query->whereJsonContains('tags', $tagId);
            }

            return;
        }

        $query->where(function (Builder $q) use ($tagIds) {
            foreach ($tagIds as $tagId) {
                $q->orWhereJsonContains('tags', $tagId);
            }
        });
    }

    private function applyOrientationCondition(Builder $query, string $orientation): void
    {
        // json_extract() works identically on MySQL and SQLite and compares
        // extracted JSON numbers numerically. Only static SQL — the user's
        // value merely selects one of three fixed comparisons.
        $comparison = match ($orientation) {
            'landscape' => '>',
            'portrait' => '<',
            'square' => '=',
        };

        $query
            ->whereNotNull('metadata->width')
            ->whereNotNull('metadata->height')
            ->whereRaw(
                "json_extract(metadata, '$.width') {$comparison} json_extract(metadata, '$.height')"
            );
    }

    private function applyUntaggedCondition(Builder $query, bool $untagged): void
    {
        if ($untagged) {
            $query->where(function (Builder $q) {
                $q->whereNull('tags')->orWhereJsonLength('tags', 0);
            });

            return;
        }

        $query->whereNotNull('tags')->whereJsonLength('tags', '>', 0);
    }

    private function validateValue(string $field, string $operator, mixed $value, int $index): mixed
    {
        $key = "rules.conditions.{$index}.value";

        return match ($field) {
            'filename', 'rights_status' => $this->requireString($value, $key),
            'extension', 'mime_type' => $operator === 'in'
                ? $this->requireStringList($value, $key)
                : $this->requireString($value, $key),
            'size' => is_numeric($value)
                ? (int) $value
                : $this->fail($key, 'The value must be a number of bytes.'),
            'folder' => $operator === 'null' || $value === null
                ? null
                : $this->requireString($value, $key),
            'tags' => $this->requireStringList($value, $key),
            'license_expires_at', 'created_at', 'updated_at' => $this->requireDate($value, $key),
            'orientation' => is_string($value) && in_array($value, self::ORIENTATIONS, true)
                ? $value
                : $this->fail($key, 'The value must be one of: landscape, portrait, square.'),
            'untagged' => is_bool($value)
                ? $value
                : $this->fail($key, 'The value must be a boolean.'),
        };
    }

    private function requireString(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->fail($key, 'The value must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function requireStringList(mixed $value, string $key): array
    {
        $values = is_string($value) ? [$value] : $value;

        if (! is_array($values) || $values === []) {
            $this->fail($key, 'The value must be a non-empty list of strings.');
        }

        foreach ($values as $item) {
            if (! is_string($item) || trim($item) === '') {
                $this->fail($key, 'The value must be a non-empty list of strings.');
            }
        }

        return array_values($values);
    }

    private function requireDate(mixed $value, string $key): string
    {
        if (! is_string($value) || strtotime($value) === false) {
            $this->fail($key, 'The value must be a valid date string.');
        }

        return $value;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
