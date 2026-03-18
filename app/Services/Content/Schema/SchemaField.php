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

    public function getConditions(): ?array
    {
        $conditions = $this->attributes['conditions'] ?? null;

        return \is_array($conditions) && ! empty($conditions['rules']) ? $conditions : null;
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

        unset($attributes['dependencies']);

        return $attributes;
    }

    protected static function normalizeConditions(array $attributes): ?array
    {
        $rawConditions = $attributes['conditions'] ?? null;

        if (! \is_array($rawConditions) && isset($attributes['dependencies']) && \is_array($attributes['dependencies'])) {
            $rawConditions = [
                'mode' => 'all',
                'rules' => $attributes['dependencies'],
            ];
        }

        if (! \is_array($rawConditions)) {
            return null;
        }

        $rules = collect($rawConditions['rules'] ?? $rawConditions)
            ->filter(fn (mixed $rule): bool => \is_array($rule) && isset($rule['field']))
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
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
