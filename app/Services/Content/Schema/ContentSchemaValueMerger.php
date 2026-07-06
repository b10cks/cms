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

            // blocks: nested schema-aware merge, handles its own recursion
            if ($type === 'blocks' && array_key_exists($fieldKey, $merged)) {
                $merged[$fieldKey] = $this->mergeNestedBlockItems(
                    $base[$fieldKey] ?? null,
                    $overrides[$fieldKey] ?? null,
                    $merged[$fieldKey],
                    $localizationOverlay,
                );

                continue;
            }

            // table (translatable): row-level merge preserving non-text columns from base
            if ($type === 'table' && $isTranslatable) {
                $merged[$fieldKey] = $this->mergeLocalizedTableValue(
                    $base[$fieldKey] ?? null,
                    $overrides[$fieldKey] ?? null,
                    $field,
                );

                continue;
            }

            // All remaining types are resolved atomically — never deep-merged.
            // Complex fields like richtext (ProseMirror), link, and meta are complete
            // values and must be substituted as a whole, not structurally merged.
            $hasBase = array_key_exists($fieldKey, $base);
            $hasOverride = array_key_exists($fieldKey, $overrides);
            $baseVal = $hasBase ? $base[$fieldKey] : null;
            $overrideVal = $hasOverride ? $overrides[$fieldKey] : null;

            if (! $isTranslatable) {
                // Non-translatable fields: base wins only when it has a concrete non-null
                // value. A null or missing base means this layer didn't contribute (e.g. an
                // overlay language that stores null for non-translatable fields, or a field
                // added to the schema after the base variant was created). In those cases
                // the array_replace_recursive result (override's value) is kept instead.
                if ($hasBase && $baseVal !== null) {
                    $merged[$fieldKey] = $baseVal;
                }

                continue;
            }

            // Translatable fields: use override atomically if non-empty, else fall back to base
            $isEmptyOverride = $type === 'richtext'
                ? $this->isEmptyRichtextValue($overrideVal)
                : $this->isEmptyOverlayValue($overrideVal);

            if (! $isEmptyOverride) {
                $merged[$fieldKey] = $overrideVal;
            } elseif ($hasBase) {
                $merged[$fieldKey] = $baseVal;
            } else {
                unset($merged[$fieldKey]);
            }
        }

        return $merged;
    }

    protected function isEmptyOverlayValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    protected function isEmptyRichtextValue(mixed $value): bool
    {
        if ($this->isEmptyOverlayValue($value)) {
            return true;
        }

        // ProseMirror doc with no content nodes at all
        if (\is_array($value) && ($value['type'] ?? null) === 'doc') {
            $content = $value['content'] ?? [];

            return ! \is_array($content) || $content === [];
        }

        return false;
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

        // The base defines row identity and order — overlays may only localize text
        // cells of existing rows. Iterating base rows keeps untouched rows alive and
        // prevents extra override rows from bleeding into the result. When base has
        // no rows (e.g. the first step of a chain merge starting from []) the
        // override rows are authoritative instead.
        $baseIsAuthoritative = $baseTable['rows'] !== [];
        $rows = [];

        foreach ($baseIsAuthoritative ? $baseTable['rows'] : $overrideTable['rows'] as $row) {
            $rowId = $row['id'];
            $baseCells = $baseIsAuthoritative ? $row['cells'] : [];
            $overrideRow = $baseIsAuthoritative ? $overrideRowsById->get($rowId) : $row;
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

        $overrideById = [];
        foreach ($overrideItems as $overrideItem) {
            if (\is_array($overrideItem) && isset($overrideItem['id']) && \is_string($overrideItem['id']) && $overrideItem['id'] !== '') {
                $overrideById[$overrideItem['id']] = $overrideItem;
            }
        }

        // In overlay mode enforce strict 1:1 mapping from base: iterate base items only so
        // that extra or duplicate items in the override cannot bleed into the result.
        // Only applies when base has items — when base is empty (e.g. first step of a chain
        // merge starting from []) fall through to the positional merge so overrides are kept.
        if ($localizationOverlay && !empty($baseItems)) {
            $result = [];

            foreach ($baseItems as $index => $baseItem) {
                if (!\is_array($baseItem)) {
                    continue;
                }

                $baseId = isset($baseItem['id']) && \is_string($baseItem['id']) && $baseItem['id'] !== '' ? $baseItem['id'] : null;
                $overrideItem = ($baseId !== null && isset($overrideById[$baseId]))
                    ? $overrideById[$baseId]
                    : (\is_array($overrideItems[$index] ?? null) ? $overrideItems[$index] : []);

                $blockSlug = (string) ($baseItem['block'] ?? '');

                if ($blockSlug === '') {
                    $result[] = $baseItem;
                    continue;
                }

                $blockSchema = $this->resolveBlockSchema($blockSlug);

                $result[] = $blockSchema !== []
                    ? $this->mergeForSchema($blockSchema, $baseItem, $overrideItem, $localizationOverlay)
                    : $baseItem;
            }

            return $result;
        }

        foreach ($mergedValue as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $baseItem = \is_array($baseItems[$index] ?? null) ? $baseItems[$index] : [];

            $baseId = isset($baseItem['id']) && \is_string($baseItem['id']) && $baseItem['id'] !== '' ? $baseItem['id'] : null;
            if ($baseId !== null && isset($overrideById[$baseId])) {
                $overrideItem = $overrideById[$baseId];
            } else {
                $overrideItem = \is_array($overrideItems[$index] ?? null) ? $overrideItems[$index] : [];
            }

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
