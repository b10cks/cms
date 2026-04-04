<?php

namespace App\Services\Content\Schema;

use App\Models\Space\DataEntry;

class OptionChoiceResolver
{
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

        return DataEntry::query()
            ->where('data_source_id', $dataSourceId)
            ->where('is_active', true)
            ->orderBy('value')
            ->orderBy('key')
            ->get(['key', 'value'])
            ->map(static fn (DataEntry $entry): array => [
                'label' => filled($entry->value) ? $entry->value : $entry->key,
                'value' => $entry->key,
            ])
            ->all();
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
