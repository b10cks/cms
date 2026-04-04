<?php

namespace App\Services\Content\Schema;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SchemaNormalizer
{
    public const array TYPE_ALIASES = [
        'block' => 'blocks',
        'blocks' => 'blocks',
        'multiAsset' => 'multi_assets',
        'multi_assets' => 'multi_assets',
        'reference' => 'references',
        'references' => 'references',
        'options' => 'options',
    ];

    public const array INDEXABLE_TYPES = [
        'text',
        'textarea',
        'markdown',
        'richtext',
        'meta',
    ];

    public const array TRANSLATABLE_TYPES = [
        'text',
        'textarea',
        'markdown',
        'richtext',
        'number',
        'option',
        'link',
        'date',
        'meta',
        'table',
    ];

    public const array VALIDATION_KEYS_BY_TYPE = [
        'text' => ['min_length', 'max_length', 'pattern'],
        'textarea' => ['min_length', 'max_length', 'pattern'],
        'markdown' => ['min_length', 'max_length'],
        'richtext' => ['min_length', 'max_length'],
        'number' => ['min', 'max'],
        'date' => ['min', 'max'],
        'multi_assets' => ['min', 'max'],
        'references' => ['min', 'max'],
        'blocks' => ['min', 'max'],
        'option' => ['allowed_values'],
        'options' => ['allowed_values', 'min', 'max'],
        'table' => ['min', 'max'],
    ];

    public function normalizeSchema(array $schema): array
    {
        $normalized = [];

        foreach ($schema as $key => $field) {
            if (!\is_array($field)) {
                continue;
            }

            $normalized[$key] = $this->normalizeField($key, $field);
        }

        return $normalized;
    }

    public function normalizeField(string $key, array $attributes): array
    {
        $type = $this->normalizeType((string) ($attributes['type'] ?? ''));
        $conditions = $this->normalizeConditions($attributes);
        $validation = $this->normalizeValidation($type, $attributes);

        $normalized = [
            'key' => $key,
            'type' => $type,
            'name' => $attributes['name'] ?? $attributes['label'] ?? Str::headline($key),
            'description' => $attributes['description'] ?? null,
            'required' => (bool) ($attributes['required'] ?? false),
            'translatable' => $this->supportsTranslation($type)
                ? (bool) ($attributes['translatable'] ?? false)
                : false,
            'indexable' => \array_key_exists('indexable', $attributes)
                ? (bool) $attributes['indexable']
                : $this->defaultIndexable($type),
            'default' => $attributes['default'] ?? null,
            'conditions' => $conditions,
            'validation' => $validation,
        ];

        if (\in_array($type, ['option', 'options'], true)) {
            $normalized['source'] = ($attributes['source'] ?? 'self') === 'datasource'
                ? 'datasource'
                : 'self';
            $normalized['data_source_id'] = $attributes['data_source_id'] ?? null;
        }

        if ($type === 'table') {
            $normalized['has_thead'] = (bool) ($attributes['has_thead'] ?? false);
            $normalized['columns'] = SchemaField::normalizeTableColumns($attributes['columns'] ?? []);
            $normalized['default'] = SchemaField::normalizeTableDefault($attributes['default'] ?? null);
        }

        foreach ($attributes as $attribute => $value) {
            if (\array_key_exists($attribute, $normalized) || $attribute === 'dependencies' || $attribute === 'label') {
                continue;
            }

            $normalized[$attribute] = $value;
        }

        return $normalized;
    }

    public function normalizeEditor(?array $editor, array $schemaKeys): array
    {
        $editor = $editor ?? [];

        if ($editor === []) {
            return [
                [
                    'header' => 'General',
                    'items' => array_values($schemaKeys),
                ]
            ];
        }

        $normalized = [];

        foreach ($editor as $page) {
            if (!\is_array($page)) {
                continue;
            }

            $items = array_values(array_filter(
                array_map(static fn($item): ?string => \is_string($item) ? $item : null, $page['items'] ?? []),
                static fn(?string $item): bool => $item !== null && \in_array($item, $schemaKeys, true)
            ));

            $normalized[] = [
                'header' => (string) ($page['header'] ?? 'General'),
                'items' => $items,
            ];
        }

        return $normalized === [] ? [
            [
                'header' => 'General',
                'items' => array_values($schemaKeys),
            ]
        ] : $normalized;
    }

    public function normalizeType(string $type): string
    {
        return self::TYPE_ALIASES[$type] ?? $type;
    }

    public function defaultIndexable(string $type): bool
    {
        return \in_array($type, self::INDEXABLE_TYPES, true);
    }

    public function supportsTranslation(string $type): bool
    {
        return \in_array($type, self::TRANSLATABLE_TYPES, true);
    }

    public function supportsIndexing(string $type): bool
    {
        return !\in_array($type, ['asset', 'multi_assets', 'references', 'boolean', 'options', 'table'], true);
    }

    protected function normalizeConditions(array $attributes): ?array
    {
        $conditions = $attributes['conditions'] ?? null;

        if ($conditions === null && isset($attributes['dependencies']) && is_array($attributes['dependencies'])) {
            $conditions = [
                'mode' => 'all',
                'rules' => array_map(fn(array $dependency): array => [
                    'field' => (string) ($dependency['field'] ?? ''),
                    'operator' => $this->normalizeLegacyOperator((string) ($dependency['operator'] ?? '=')),
                    'value' => $dependency['value'] ?? null,
                ], $attributes['dependencies']),
            ];
        }

        if (!\is_array($conditions)) {
            return null;
        }

        $mode = strtolower((string) ($conditions['mode'] ?? 'all'));
        $rules = [];

        foreach ($conditions['rules'] ?? [] as $rule) {
            if (!\is_array($rule) || empty($rule['field'])) {
                continue;
            }

            $rules[] = Arr::whereNotNull([
                'field' => (string) $rule['field'],
                'operator' => $this->normalizeLegacyOperator((string) ($rule['operator'] ?? 'equals')),
                'value' => $rule['value'] ?? null,
            ]);
        }

        if ($rules === []) {
            return null;
        }

        return [
            'mode' => \in_array($mode, ['all', 'any'], true) ? $mode : 'all',
            'rules' => $rules,
        ];
    }

    protected function normalizeValidation(string $type, array $attributes): ?array
    {
        $validation = $attributes['validation'] ?? null;
        $normalized = \is_array($validation) ? $validation : [];

        if (\array_key_exists('min_items', $normalized) && !\array_key_exists('min', $normalized)) {
            $normalized['min'] = $normalized['min_items'];
        }

        if (\array_key_exists('max_items', $normalized) && !\array_key_exists('max', $normalized)) {
            $normalized['max'] = $normalized['max_items'];
        }

        unset($normalized['min_items'], $normalized['max_items']);

        $fallbacks = match ($type) {
            'text', 'textarea', 'markdown', 'richtext' => [
                'min_length' => $attributes['min_length'] ?? null,
                'max_length' => $attributes['max_length'] ?? $attributes['maximum'] ?? null,
                'pattern' => $attributes['pattern'] ?? null,
            ],
            'number', 'date' => [
                'min' => $attributes['min'] ?? $attributes['minimum'] ?? null,
                'max' => $attributes['max'] ?? $attributes['maximum'] ?? null,
            ],
            'multi_assets', 'references', 'blocks', 'options' => [
                'min' => $attributes['min'] ?? null,
                'max' => $attributes['max'] ?? null,
            ],
            'table' => [
                'min' => $attributes['min'] ?? null,
                'max' => $attributes['max'] ?? null,
            ],
            'option' => [
                'allowed_values' => ($attributes['source'] ?? 'self') === 'datasource'
                    ? null
                    : ($attributes['allowed_values']
                        ?? array_values(array_filter(array_map(
                            static fn($option): ?string => is_array($option) ? ($option['value'] ?? null) : null,
                            $attributes['options'] ?? []
                        )))),
            ],
            'options' => [
                'allowed_values' => ($attributes['source'] ?? 'self') === 'datasource'
                    ? null
                    : ($attributes['allowed_values']
                        ?? array_values(array_filter(array_map(
                            static fn($option): ?string => is_array($option) ? ($option['value'] ?? null) : null,
                            $attributes['options'] ?? []
                        )))),
                'min' => $attributes['min'] ?? null,
                'max' => $attributes['max'] ?? null,
            ],
            default => [],
        };

        foreach ($fallbacks as $key => $value) {
            if ($value !== null && !\array_key_exists($key, $normalized)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    protected function normalizeLegacyOperator(string $operator): string
    {
        return match ($operator) {
            '=', '==' => 'equals',
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
    }
}
