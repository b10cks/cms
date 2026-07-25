<?php

namespace App\Services\Content\Schema;

use Illuminate\Contracts\Support\Arrayable;

class SchemaField implements Arrayable
{
    protected const array TYPE_ALIASES = [
        'block' => 'blocks',
        'multiAsset' => 'multi_assets',
        'reference' => 'references',
    ];

    protected const array TRANSLATABLE_TYPES = [
        'text',
        'textarea',
        'markdown',
        'richtext',
        'number',
        'link',
        'meta',
        'date',
        'table',
    ];

    protected const array INDEXABLE_DEFAULTS = [
        'text' => true,
        'textarea' => true,
        'markdown' => true,
        'richtext' => true,
        'meta' => false,
        'link' => false,
        'number' => false,
        'boolean' => false,
        'date' => false,
        'asset' => false,
        'multi_assets' => false,
        'references' => false,
        'blocks' => false,
        'option' => false,
        'options' => false,
        'table' => false,
    ];

    protected string $key;

    protected array $attributes;

    public function __construct(string $key, array $attributes)
    {
        $this->key = $key;
        $this->attributes = self::normalizeAttributes($key, $attributes);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getType(): string
    {
        return $this->attributes['type'] ?? '';
    }

    public function getLabel(): string
    {
        return $this->attributes['name'] ?? $this->attributes['label'] ?? $this->key;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function isRequired(): bool
    {
        return (bool) ($this->attributes['required'] ?? false);
    }

    public function isTranslatable(): bool
    {
        return (bool) ($this->attributes['translatable'] ?? false);
    }

    public function isIndexable(): bool
    {
        return (bool) ($this->attributes['indexable'] ?? false);
    }

    /**
     * Whether the value is owned by the system rather than by the editor.
     *
     * Readonly fields are rendered disabled and, more importantly, are restored
     * from the stored entry on every submission — a client that posts a value
     * anyway cannot overwrite them.
     */
    public function isReadonly(): bool
    {
        return (bool) ($this->attributes['readonly'] ?? false);
    }

    public function getConditions(): ?array
    {
        $conditions = $this->attributes['conditions'] ?? null;

        return \is_array($conditions) && !empty($conditions['rules']) ? $conditions : null;
    }

    public function getDependencies(): array
    {
        return $this->getConditions()['rules'] ?? [];
    }

    public function getValidation(): array
    {
        return $this->attributes['validation'] ?? [];
    }

    public function getValidationValue(string $key, mixed $default = null): mixed
    {
        $validation = $this->getValidation();

        return $validation[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public static function canonicalizeType(?string $type): string
    {
        $type = (string) $type;

        return self::TYPE_ALIASES[$type] ?? $type;
    }

    public static function supportsTranslation(string $type): bool
    {
        return \in_array(self::canonicalizeType($type), self::TRANSLATABLE_TYPES, true);
    }

    public static function defaultIndexable(string $type): bool
    {
        return self::INDEXABLE_DEFAULTS[self::canonicalizeType($type)] ?? false;
    }

    public static function normalizeAttributes(string $key, array $attributes): array
    {
        $attributes['key'] = $key;
        $attributes['type'] = self::canonicalizeType($attributes['type'] ?? '');
        $attributes['name'] = $attributes['name'] ?? $attributes['label'] ?? $key;
        $attributes['description'] = $attributes['description'] ?? null;
        $attributes['required'] = (bool) ($attributes['required'] ?? false);
        $attributes['translatable'] = self::supportsTranslation($attributes['type'])
            ? (bool) ($attributes['translatable'] ?? false)
            : false;
        $attributes['indexable'] = (bool) ($attributes['indexable'] ?? self::defaultIndexable($attributes['type']));
        $attributes['conditions'] = self::normalizeConditions($attributes);
        $attributes['validation'] = self::normalizeValidation($attributes);

        if (\in_array($attributes['type'], ['option', 'options'], true)) {
            $attributes['source'] = ($attributes['source'] ?? 'self') === 'datasource'
                ? 'datasource'
                : 'self';
            $attributes['data_source_id'] = $attributes['data_source_id'] ?? null;
        }

        if ($attributes['type'] === 'serial') {
            $attributes['format'] = \is_string($attributes['format'] ?? null) && trim($attributes['format']) !== ''
                ? trim($attributes['format'])
                : '{counter}';
            $attributes['scope'] = self::normalizeScopeDimensions($attributes['scope'] ?? null);
            $attributes['unique'] = \in_array($attributes['unique'] ?? null, ['scope', 'block', 'space', 'none'], true)
                ? $attributes['unique']
                : 'scope';
            $attributes['on_move'] = ($attributes['on_move'] ?? 'keep') === 'reallocate' ? 'reallocate' : 'keep';
            $attributes['editable'] = (bool) ($attributes['editable'] ?? false);
            $attributes['default'] = null;
        }

        if ($attributes['type'] === 'table') {
            $attributes['has_thead'] = (bool) ($attributes['has_thead'] ?? false);
            $attributes['min'] = array_key_exists('min', $attributes)
                ? $attributes['min']
                : ($attributes['validation']['min'] ?? null);
            $attributes['max'] = array_key_exists('max', $attributes)
                ? $attributes['max']
                : ($attributes['validation']['max'] ?? null);
            $attributes['columns'] = self::normalizeTableColumns($attributes['columns'] ?? []);
            $attributes['default'] = self::normalizeTableDefault($attributes['default'] ?? null);
        }

        // Resolved after the type branches so `serial` has already settled its
        // `editable` flag.
        $attributes['readonly'] = self::resolveReadonly($attributes);

        unset($attributes['dependencies']);

        return $attributes;
    }

    protected static function resolveReadonly(array $attributes): bool
    {
        if (($attributes['type'] ?? '') === 'serial') {
            return ! ($attributes['editable'] ?? false);
        }

        return (bool) ($attributes['readonly'] ?? false);
    }

    /**
     * @return array<int, string>
     */
    public static function normalizeScopeDimensions(mixed $scope): array
    {
        $dimensions = ['space', 'block', 'parent', 'language', 'year', 'month'];

        if (! \is_array($scope)) {
            return ['block', 'parent'];
        }

        // Emitted in a fixed order so two equivalent scopes are the same scope.
        $normalized = array_values(array_filter(
            $dimensions,
            static fn (string $dimension): bool => \in_array($dimension, $scope, true),
        ));

        return $normalized === [] ? ['block', 'parent'] : $normalized;
    }

    protected static function normalizeConditions(array $attributes): ?array
    {
        $rawConditions = $attributes['conditions'] ?? null;

        if (!\is_array($rawConditions) && isset($attributes['dependencies']) && \is_array($attributes['dependencies'])) {
            $rawConditions = [
                'mode' => 'all',
                'rules' => $attributes['dependencies'],
            ];
        }

        if (!\is_array($rawConditions)) {
            return null;
        }

        $rules = collect($rawConditions['rules'] ?? $rawConditions)
            ->filter(fn(mixed $rule): bool => \is_array($rule) && isset($rule['field']))
            ->map(function (array $rule): array {
                $operator = (string) ($rule['operator'] ?? 'equals');
                $operator = match ($operator) {
                    '=' => 'equals',
                    '!=' => 'not_equals',
                    '>' => 'gt',
                    '>=' => 'gte',
                    '<' => 'lt',
                    '<=' => 'lte',
                    'in' => 'in',
                    'not_in' => 'not_in',
                    'empty' => 'is_empty',
                    'not_empty' => 'is_not_empty',
                    default => $operator,
                };

                return [
                    'field' => (string) $rule['field'],
                    'operator' => $operator,
                    'value' => $rule['value'] ?? null,
                ];
            })
            ->values()
            ->all();

        if ($rules === []) {
            return null;
        }

        return [
            'mode' => ($rawConditions['mode'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => $rules,
        ];
    }

    protected static function normalizeValidation(array $attributes): array
    {
        $validation = \is_array($attributes['validation'] ?? null) ? $attributes['validation'] : [];

        $mapping = [
            'min' => ['min', 'minimum'],
            'max' => ['max', 'maximum'],
            'min_length' => ['min_length', 'minimum_length'],
            'max_length' => ['max_length', 'maximum_length', 'maximum'],
            'pattern' => ['pattern'],
            'allowed_values' => ['allowed_values'],
            'min_items' => ['min_items', 'min'],
            'max_items' => ['max_items', 'max'],
        ];

        foreach ($mapping as $target => $sources) {
            if (array_key_exists($target, $validation)) {
                continue;
            }

            foreach ($sources as $source) {
                if (array_key_exists($source, $attributes)) {
                    $validation[$target] = $attributes[$source];
                    break;
                }
            }
        }

        return array_filter(
            $validation,
            static fn(mixed $value): bool => $value !== null && $value !== ''
        );
    }

    public static function normalizeTableColumns(mixed $columns): array
    {
        if (!\is_array($columns)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $column): ?array {
                if (!\is_array($column)) {
                    return null;
                }

                $type = (string) ($column['type'] ?? '');
                $normalizedType = \in_array($type, ['text', 'number', 'option', 'boolean'], true)
                    ? $type
                    : $type;

                $normalized = [
                    'key' => (string) ($column['key'] ?? ''),
                    'label' => (string) ($column['label'] ?? ''),
                    'type' => $normalizedType,
                ];

                if ($normalizedType === 'option') {
                    $normalized['source'] = ($column['source'] ?? 'self') === 'datasource'
                        ? 'datasource'
                        : 'self';
                    $normalized['options'] = \is_array($column['options'] ?? null)
                        ? array_values(array_filter(array_map(
                            static fn(mixed $option): ?array => \is_array($option)
                            ? [
                                'name' => (string) ($option['name'] ?? ''),
                                'value' => (string) ($option['value'] ?? ''),
                            ]
                            : null,
                            $column['options'],
                        )))
                        : [];
                    $normalized['data_source_id'] = $column['data_source_id'] ?? null;
                }

                return $normalized;
            },
            $columns,
        )));
    }

    public static function normalizeTableDefault(mixed $default): array
    {
        if (!\is_array($default)) {
            return [
                'header' => [],
                'rows' => [],
            ];
        }

        $header = \is_array($default['header'] ?? null)
            ? array_filter(
                $default['header'],
                static fn(mixed $value, mixed $key): bool => \is_string($key) && \is_string($value),
                ARRAY_FILTER_USE_BOTH,
            )
            : [];

        $rows = \is_array($default['rows'] ?? null)
            ? array_values(array_filter(array_map(
                static function (mixed $row): ?array {
                    if (!\is_array($row)) {
                        return null;
                    }

                    return [
                        'id' => (string) ($row['id'] ?? ''),
                        'cells' => \is_array($row['cells'] ?? null) ? $row['cells'] : [],
                    ];
                },
                $default['rows'],
            )))
            : [];

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }
}
