<?php

namespace App\Services\ContentData;

use App\DTOs\ContentData\TranslationDocument;
use App\DTOs\ContentData\TranslationUnit;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Content\ContentI18nService;
use App\Services\Content\RichText\ProseMirrorHtmlConverter;
use App\Services\Content\Schema\ContentSchemaValueMerger;
use App\Services\Content\Schema\SchemaField;
use Illuminate\Support\Collection;

/**
 * Walks content documents against their block schema and produces the flat list of
 * translatable units. The same walk (collectUnits) is used both to build export
 * documents and to resolve target-tree write paths on import, guaranteeing the
 * stable-id scheme is identical in both directions.
 */
class ContentTranslationExtractor
{
    /** Translatable sub-keys of a `meta` field value. */
    private const META_KEYS = ['title', 'description', 'ogTitle', 'ogDescription'];

    public function __construct(
        private readonly ContentSchemaValueMerger $schema,
        private readonly ContentI18nService $i18n,
        private readonly ProseMirrorHtmlConverter $richtext,
    ) {
    }

    /**
     * Build a translation document per canonical content, gathering the source value
     * from the canonical row and existing translations from each language row.
     *
     * @param  Collection<int, Content>  $canonicals  Canonical (default-language) contents, each with its family resolvable.
     * @return array<int, TranslationDocument>
     */
    public function extractForContents(Space $space, Collection $canonicals): array
    {
        $defaultLanguage = $space->settings->getDefaultLanguage();
        $targetLanguages = array_values(array_filter(
            $space->settings->getEnabledLanguages(),
            static fn (string $language): bool => $language !== $defaultLanguage,
        ));

        $documents = [];

        foreach ($canonicals as $canonical) {
            $document = $this->extractDocument($canonical, $defaultLanguage, $targetLanguages);

            if ($document->units !== []) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    private function extractDocument(Content $canonical, string $defaultLanguage, array $targetLanguages): TranslationDocument
    {
        $rootSchema = $canonical->block?->schema?->toArray() ?? [];

        $sourceUnits = $this->collectUnits($canonical->getCurrentContent(), $rootSchema);

        $family = $this->i18n->getFamily($canonical)->keyBy('language_iso');

        $targetValues = [];
        foreach ($targetLanguages as $language) {
            /** @var Content|null $row */
            $row = $family->get($language);
            $targetValues[$language] = $row
                ? array_map(
                    static fn (array $unit): string => $unit['value'],
                    $this->collectUnits($row->getCurrentContent(), $rootSchema),
                )
                : [];
        }

        $units = [];
        foreach ($sourceUnits as $id => $unit) {
            $targets = [];
            foreach ($targetLanguages as $language) {
                $value = $targetValues[$language][$id] ?? '';
                if ($value !== '') {
                    $targets[$language] = $value;
                }
            }

            // Skip units that carry no source and no existing translation — nothing to translate.
            if ($unit['value'] === '' && $targets === []) {
                continue;
            }

            $units[] = new TranslationUnit(
                id: $id,
                fieldKey: $unit['fieldKey'],
                type: $unit['type'],
                label: $unit['label'],
                note: $unit['note'],
                path: $unit['path'],
                source: $unit['value'],
                targets: $targets,
            );
        }

        return new TranslationDocument(
            contentId: $canonical->id,
            name: (string) $canonical->name,
            slug: (string) $canonical->slug,
            sourceLanguage: $defaultLanguage,
            languages: $targetLanguages,
            units: $units,
        );
    }

    /**
     * Collect every translatable unit from a content tree, keyed by stable id.
     *
     * @param  array<string, mixed>  $tree
     * @param  array<string, mixed>  $rootSchema
     * @return array<string, array{fieldKey: string, type: string, label: string, note: string, path: array<int, string|int>, value: string}>
     */
    public function collectUnits(array $tree, array $rootSchema): array
    {
        $units = [];
        $this->walk($tree, $rootSchema, '', [], '', $units);

        return $units;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, mixed>  $schema
     * @param  array<int, string|int>  $pathPrefix
     * @param  array<string, array<string, mixed>>  $units
     */
    private function walk(array $tree, array $schema, string $idPrefix, array $pathPrefix, string $notePrefix, array &$units): void
    {
        foreach ($schema as $fieldKey => $definition) {
            if (! \is_array($definition)) {
                continue;
            }

            $type = SchemaField::canonicalizeType((string) ($definition['type'] ?? ''));
            $label = (string) ($definition['name'] ?? $definition['label'] ?? $fieldKey);
            $value = $tree[$fieldKey] ?? null;

            if ($type === 'blocks') {
                $this->walkBlocks((array) ($value ?? []), $fieldKey, $label, $idPrefix, $pathPrefix, $notePrefix, $units);

                continue;
            }

            if (! (bool) ($definition['translatable'] ?? false) || ! SchemaField::supportsTranslation($type)) {
                continue;
            }

            $id = $idPrefix . $fieldKey;
            $path = [...$pathPrefix, $fieldKey];
            $note = $notePrefix . $label;

            match ($type) {
                'richtext' => $this->emit($units, $id, $fieldKey, $type, $label, $note, $path, $this->richtextToHtml($value)),
                'meta' => $this->emitMeta($units, $id, $fieldKey, $label, $note, $path, $value),
                'table' => $this->emitTable($units, $id, $fieldKey, $label, $note, $path, $value, $definition),
                'link' => null, // Links carry no free translatable prose; skipped by design.
                default => $this->emit($units, $id, $fieldKey, $type, $label, $note, $path, $this->scalarToString($value)),
            };
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, string|int>  $pathPrefix
     * @param  array<string, array<string, mixed>>  $units
     */
    private function walkBlocks(array $items, string $fieldKey, string $label, string $idPrefix, array $pathPrefix, string $notePrefix, array &$units): void
    {
        foreach ($items as $index => $item) {
            if (! \is_array($item)) {
                continue;
            }

            $blockSlug = (string) ($item['block'] ?? '');
            if ($blockSlug === '') {
                continue;
            }

            $itemId = isset($item['id']) && \is_string($item['id']) && $item['id'] !== ''
                ? $item['id']
                : (string) $index;

            $nestedSchema = $this->schema->resolveBlockSchema($blockSlug);
            if ($nestedSchema === []) {
                continue;
            }

            $this->walk(
                $item,
                $nestedSchema,
                $idPrefix . $fieldKey . '.' . $itemId . '.',
                [...$pathPrefix, $fieldKey, $index],
                $notePrefix . $label . ' › ',
                $units,
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<int, string|int>  $path
     */
    private function emitMeta(array &$units, string $id, string $fieldKey, string $label, string $note, array $path, mixed $value): void
    {
        $meta = \is_array($value) ? $value : [];

        foreach (self::META_KEYS as $metaKey) {
            $this->emit(
                $units,
                $id . '.' . $metaKey,
                $fieldKey,
                'meta',
                $label . ' · ' . $metaKey,
                $note . ' · ' . $metaKey,
                [...$path, $metaKey],
                $this->scalarToString($meta[$metaKey] ?? null),
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<int, string|int>  $path
     * @param  array<string, mixed>  $definition
     */
    private function emitTable(array &$units, string $id, string $fieldKey, string $label, string $note, array $path, mixed $value, array $definition): void
    {
        $columns = SchemaField::normalizeTableColumns($definition['columns'] ?? []);
        $textColumns = array_values(array_filter(
            $columns,
            static fn (array $column): bool => ($column['type'] ?? '') === 'text',
        ));

        if ($textColumns === []) {
            return;
        }

        $table = \is_array($value) ? $value : [];
        $header = \is_array($table['header'] ?? null) ? $table['header'] : [];
        $rows = \is_array($table['rows'] ?? null) ? $table['rows'] : [];

        foreach ($textColumns as $column) {
            $columnKey = $column['key'];
            $this->emit(
                $units,
                $id . '.header.' . $columnKey,
                $fieldKey,
                'table',
                $label . ' · ' . $columnKey,
                $note . ' · header · ' . $columnKey,
                [...$path, 'header', $columnKey],
                $this->scalarToString($header[$columnKey] ?? null),
            );
        }

        foreach ($rows as $index => $row) {
            if (! \is_array($row)) {
                continue;
            }

            $rowId = isset($row['id']) && \is_string($row['id']) && $row['id'] !== '' ? $row['id'] : (string) $index;
            $cells = \is_array($row['cells'] ?? null) ? $row['cells'] : [];

            foreach ($textColumns as $column) {
                $columnKey = $column['key'];
                $this->emit(
                    $units,
                    $id . '.rows.' . $rowId . '.' . $columnKey,
                    $fieldKey,
                    'table',
                    $label . ' · ' . $columnKey,
                    $note . ' · row · ' . $columnKey,
                    [...$path, 'rows', $index, 'cells', $columnKey],
                    $this->scalarToString($cells[$columnKey] ?? null),
                );
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<int, string|int>  $path
     */
    private function emit(array &$units, string $id, string $fieldKey, string $type, string $label, string $note, array $path, string $value): void
    {
        $units[$id] = [
            'fieldKey' => $fieldKey,
            'type' => $type,
            'label' => $label,
            'note' => $note,
            'path' => $path,
            'value' => $value,
        ];
    }

    private function richtextToHtml(mixed $value): string
    {
        if (! \is_array($value)) {
            return '';
        }

        return $this->richtext->toHtml($value);
    }

    private function scalarToString(mixed $value): string
    {
        if ($value === null || \is_array($value)) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
