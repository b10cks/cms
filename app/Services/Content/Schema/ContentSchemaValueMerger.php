<?php

namespace App\Services\Content\Schema;

use App\Models\Space\Block;

class ContentSchemaValueMerger
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $blockSchemaCache = [];

    protected bool $blockSchemaCacheLoaded = false;

    /**
     * @param  array<string, mixed>|BlockSchema  $schema
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function mergeForSchema(
        array|BlockSchema $schema,
        array $base,
        array $overrides,
        bool $localizationOverlay = false,
    ): array {
        $schemaArray = $schema instanceof BlockSchema ? $schema->toArray() : $schema;
        $merged = array_replace_recursive($base, $overrides);

        if (!$localizationOverlay || $schemaArray === []) {
            return $merged;
        }

        foreach ($schemaArray as $fieldKey => $field) {
            if (!\is_array($field)) {
                continue;
            }

            $type = SchemaField::canonicalizeType((string) ($field['type'] ?? ''));
            $isTranslatable = (bool) ($field['translatable'] ?? false);

            if ($type === 'table' && ($field['translatable'] ?? false)) {
                $merged[$fieldKey] = $this->mergeLocalizedTableValue(
                    $base[$fieldKey] ?? null,
                    $overrides[$fieldKey] ?? null,
                    $field,
                );

                continue;
            }

            if ($type === 'blocks' && array_key_exists($fieldKey, $merged)) {
                $merged[$fieldKey] = $this->mergeNestedBlockItems(
                    $base[$fieldKey] ?? null,
                    $overrides[$fieldKey] ?? null,
                    $merged[$fieldKey],
                    $localizationOverlay,
                );

                continue;
            }

            if (! $isTranslatable && array_key_exists($fieldKey, $overrides) && $this->isEmptyOverlayValue($overrides[$fieldKey])) {
                if (array_key_exists($fieldKey, $base)) {
                    $merged[$fieldKey] = $base[$fieldKey];
                } else {
                    unset($merged[$fieldKey]);
                }
            }
        }

        return $merged;
    }

    protected function isEmptyOverlayValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    protected function mergeLocalizedTableValue(mixed $baseValue, mixed $overrideValue, array $field): array
    {
        $columns = $this->normalizeColumns($field['columns'] ?? []);
        $baseTable = $this->normalizeTableValue($baseValue);
        $overrideTable = $this->normalizeTableValue($overrideValue);
        $overrideRowsById = collect($overrideTable['rows'])
            ->filter(fn(array $row): bool => isset($row['id']) && \is_string($row['id']) && $row['id'] !== '')
            ->keyBy('id');

        $header = [];

        foreach ($columns as $column) {
            $columnKey = $column['key'];
            $fallbackLabel = $column['label'] !== '' ? $column['label'] : $columnKey;

            $header[$columnKey] = isset($overrideTable['header'][$columnKey])
            && \is_string($overrideTable['header'][$columnKey])
                ? $overrideTable['header'][$columnKey]
                : (
                    isset($baseTable['header'][$columnKey]) && \is_string($baseTable['header'][$columnKey])
                        ? $baseTable['header'][$columnKey]
                        : $fallbackLabel
                );
        }

        $rows = [];
        $baseRowsById = collect($baseTable['rows'])
            ->filter(fn(array $row): bool => isset($row['id']) && \is_string($row['id']) && $row['id'] !== '')
            ->keyBy('id');

        foreach ($overrideTable['rows'] as $overrideRow) {
            $rowId = $overrideRow['id'] ?? null;

            if (!\is_string($rowId) || $rowId === '') {
                continue;
            }

            $baseRow = $baseRowsById->get($rowId);
            $baseCells = \is_array($baseRow['cells'] ?? null) ? $baseRow['cells'] : [];
            $overrideCells = \is_array($overrideRow['cells'] ?? null) ? $overrideRow['cells'] : [];
            $mergedCells = $baseCells;

            foreach ($columns as $column) {
                $columnKey = $column['key'];

                if ($column['type'] === 'text') {
                    if (array_key_exists($columnKey, $overrideCells) && \is_string($overrideCells[$columnKey])) {
                        $mergedCells[$columnKey] = $overrideCells[$columnKey];
                    } elseif (array_key_exists($columnKey, $baseCells)) {
                        $mergedCells[$columnKey] = $baseCells[$columnKey];
                    }

                    continue;
                }

                if (array_key_exists($columnKey, $baseCells)) {
                    $mergedCells[$columnKey] = $baseCells[$columnKey];
                } elseif (array_key_exists($columnKey, $overrideCells)) {
                    $mergedCells[$columnKey] = $overrideCells[$columnKey];
                }
            }

            $rows[] = [
                'id' => $rowId,
                'cells' => $mergedCells,
            ];
        }

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, type: string}>
     */
    protected function normalizeColumns(mixed $columns): array
    {
        if (!\is_array($columns)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $column): ?array {
            if (!\is_array($column)) {
                return null;
            }

            $key = (string) ($column['key'] ?? '');

            if ($key === '') {
                return null;
            }

            return [
                'key' => $key,
                'label' => (string) ($column['label'] ?? ''),
                'type' => (string) ($column['type'] ?? 'text'),
            ];
        }, $columns)));
    }

    /**
     * @return array{header: array<string, string>, rows: array<int, array{id: string, cells: array<string, mixed>}>}
     */
    protected function normalizeTableValue(mixed $value): array
    {
        if (!\is_array($value)) {
            return [
                'header' => [],
                'rows' => [],
            ];
        }

        $header = \is_array($value['header'] ?? null)
            ? array_filter(
                $value['header'],
                static fn(mixed $entry, mixed $key): bool => \is_string($key) && \is_string($entry),
                ARRAY_FILTER_USE_BOTH,
            )
            : [];

        $rows = \is_array($value['rows'] ?? null)
            ? array_values(array_filter(array_map(static function (mixed $row): ?array {
                if (!\is_array($row)) {
                    return null;
                }

                $rowId = $row['id'] ?? null;

                if (!\is_string($rowId) || $rowId === '') {
                    return null;
                }

                return [
                    'id' => $rowId,
                    'cells' => \is_array($row['cells'] ?? null) ? $row['cells'] : [],
                ];
            }, $value['rows'])))
            : [];

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }

    protected function mergeNestedBlockItems(
        mixed $baseValue,
        mixed $overrideValue,
        mixed $mergedValue,
        bool $localizationOverlay,
    ): mixed {
        if (!\is_array($mergedValue)) {
            return $mergedValue;
        }

        $baseItems = \is_array($baseValue) ? $baseValue : [];
        $overrideItems = \is_array($overrideValue) ? $overrideValue : [];

        $this->loadBlockSchemaCache();

        foreach ($mergedValue as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $baseItem = \is_array($baseItems[$index] ?? null) ? $baseItems[$index] : [];
            $overrideItem = \is_array($overrideItems[$index] ?? null) ? $overrideItems[$index] : [];
            $blockSlug = (string) ($item['block'] ?? $baseItem['block'] ?? $overrideItem['block'] ?? '');

            if ($blockSlug === '') {
                continue;
            }

            $blockSchema = $this->resolveBlockSchema($blockSlug);

            if ($blockSchema === []) {
                continue;
            }

            $mergedValue[$index] = $this->mergeForSchema($blockSchema, $baseItem, $overrideItem, $localizationOverlay);
        }

        return $mergedValue;
    }

    protected function loadBlockSchemaCache(): void
    {
        if ($this->blockSchemaCacheLoaded) {
            return;
        }

        $request = ! app()->runningInConsole() && app()->bound('request') ? request() : null;
        $requestCacheKey = 'content.schema_value_merger.block_schema_cache';

        if ($request?->attributes->has($requestCacheKey)) {
            /** @var array<string, array<string, mixed>> $cached */
            $cached = $request->attributes->get($requestCacheKey, []);
            $this->blockSchemaCache = $cached;
            $this->blockSchemaCacheLoaded = true;

            return;
        }

        $this->blockSchemaCache = Block::query()
            ->select(['slug', 'schema'])
            ->get()
            ->mapWithKeys(static fn(Block $block): array => [
                $block->slug => $block->schema?->toArray() ?? [],
            ])
            ->all();

        $this->blockSchemaCacheLoaded = true;
        $request?->attributes->set($requestCacheKey, $this->blockSchemaCache);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveBlockSchema(string $slug): array
    {
        $this->loadBlockSchemaCache();

        return $this->blockSchemaCache[$slug] ?? [];
    }
}
