<?php

namespace App\Services\Content\Schema;

use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Space\ShapeValue;

class OptionChoiceResolver
{
    /**
     * @var array<string, array<int, array{label: string, value: string}>>
     */
    protected array $datasourceChoicesCache = [];

    /**
     * @param  array<string, mixed>|SchemaField  $field
     * @return array<int, array{label: string, value: string}>
     */
    public function resolveChoices(array|SchemaField $field): array
    {
        return $this->source($field) === 'datasource'
            ? $this->resolveDatasourceChoices($field)
            : $this->resolveInlineChoices($field);
    }

    /**
     * @param  array<string, mixed>|SchemaField  $field
     * @return array<int, string>
     */
    public function resolveAllowedValues(array|SchemaField $field): array
    {
        return array_values(array_unique(array_map(
            static fn (array $choice): string => $choice['value'],
            $this->resolveChoices($field),
        )));
    }

    /**
     * @param  array<string, mixed>|SchemaField  $field
     * @return array<int, array{label: string, value: string}>
     */
    protected function resolveInlineChoices(array|SchemaField $field): array
    {
        $options = $this->attribute($field, 'options', []);

        if (! is_array($options)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $option): ?array {
                if (! is_array($option)) {
                    return null;
                }

                $value = (string) ($option['value'] ?? '');

                if ($value === '') {
                    return null;
                }

                $label = trim((string) ($option['name'] ?? ''));

                return [
                    'label' => $label !== '' ? $label : $value,
                    'value' => $value,
                ];
            },
            $options,
        )));
    }

    /**
     * @param  array<string, mixed>|SchemaField  $field
     * @return array<int, array{label: string, value: string}>
     */
    protected function resolveDatasourceChoices(array|SchemaField $field): array
    {
        $dataSourceId = $this->attribute($field, 'data_source_id');

        if (! is_string($dataSourceId) || $dataSourceId === '') {
            return [];
        }

        if (array_key_exists($dataSourceId, $this->datasourceChoicesCache)) {
            return $this->datasourceChoicesCache[$dataSourceId];
        }

        $request = ! app()->runningInConsole() && app()->bound('request') ? request() : null;
        $requestCacheKey = "content.option_choice_resolver.datasource.{$dataSourceId}";

        if ($request?->attributes->has($requestCacheKey)) {
            /** @var array<int, array{label: string, value: string}> $cached */
            $cached = $request->attributes->get($requestCacheKey, []);
            $this->datasourceChoicesCache[$dataSourceId] = $cached;

            return $cached;
        }

        $shape = DataSource::query()->find($dataSourceId)?->shape;

        $choices = DataEntry::query()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->orderBy('value')
            ->orderBy('key')
            ->get(['key', 'value'])
            ->map(static fn (DataEntry $entry): array => [
                'label' => ShapeValue::label($entry->value, $shape, $entry->key),
                'value' => $entry->key,
            ])
            ->all();

        $this->datasourceChoicesCache[$dataSourceId] = $choices;
        $request?->attributes->set($requestCacheKey, $choices);

        return $choices;
    }

    /**
     * @param  array<string, mixed>|SchemaField  $field
     */
    protected function source(array|SchemaField $field): string
    {
        return $this->attribute($field, 'source', 'self') === 'datasource'
            ? 'datasource'
            : 'self';
    }

    /**
     * @param  array<string, mixed>|SchemaField  $field
     */
    protected function attribute(array|SchemaField $field, string $key, mixed $default = null): mixed
    {
        if ($field instanceof SchemaField) {
            return $field->getAttribute($key, $default);
        }

        return $field[$key] ?? $default;
    }
}
