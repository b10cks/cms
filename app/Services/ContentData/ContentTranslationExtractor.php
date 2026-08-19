<?php

namespace App\Services\ContentData;

use App\DTOs\ContentData\TranslationDocument;
use App\DTOs\ContentData\TranslationUnit;
use App\Models\Management\Space;
use App\Models\Space\Block;
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

    /** Block types a content can be built on; `nestable` blocks only ever live inside these. */
    private const CONTENT_BLOCK_TYPES = ['root', 'universal', 'single'];

    /** @var array<string, array<string, array{type: string, label: string, translatable: bool}>>|null */
    private ?array $schemaFieldsByBlock = null;

    public function __construct(
        private readonly ContentSchemaValueMerger $schema,
        private readonly ContentI18nService $i18n,
        private readonly ProseMirrorHtmlConverter $richtext,
    ) {}

    /**
     * Build a translation document per canonical content, gathering the source value
     * from the canonical row and existing translations from each language row.
     *
     * @param  Collection<int, Content>  $canonicals  Canonical (default-language) contents, each with its family resolvable.
     * @param  array<int, string>|null  $fieldKeys  Restrict units to these schema field keys (any nesting level).
     * @param  array<int, string>|null  $languages  Restrict targets to these languages (default language is always excluded).
     * @param  bool  $includeEmptyUnits  Keep units with neither source nor translations, and documents without units.
     * @param  bool  $includeNonTranslatable  Also include supported fields without the translatable flag (source-language editing only).
     * @return array<int, TranslationDocument>
     */
    public function extractForContents(
        Space $space,
        Collection $canonicals,
        ?array $fieldKeys = null,
        ?array $languages = null,
        bool $includeEmptyUnits = false,
        bool $includeNonTranslatable = false,
    ): array {
        $defaultLanguage = $space->settings->getDefaultLanguage();
        $targetLanguages = array_values(array_filter(
            $space->settings->getEnabledLanguages(),
            static fn (string $language): bool => $language !== $defaultLanguage
                && ($languages === null || \in_array($language, $languages, true)),
        ));

        $documents = [];

        foreach ($canonicals as $canonical) {
            $document = $this->extractDocument($canonical, $defaultLanguage, $targetLanguages, $fieldKeys, $includeEmptyUnits, $includeNonTranslatable);

            if ($includeEmptyUnits || $document->units !== []) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    private function extractDocument(
        Content $canonical,
        string $defaultLanguage,
        array $targetLanguages,
        ?array $fieldKeys = null,
        bool $includeEmptyUnits = false,
        bool $includeNonTranslatable = false,
    ): TranslationDocument {
        $rootSchema = $canonical->block?->schema?->toArray() ?? [];

        $sourceUnits = $this->collectUnits($canonical->getCurrentContent(), $rootSchema, $includeNonTranslatable);

        if ($fieldKeys !== null) {
            $sourceUnits = array_filter(
                $sourceUnits,
                static fn (array $unit): bool => \in_array($unit['fieldKey'], $fieldKeys, true),
            );
        }

        $family = $this->i18n->getFamily($canonical)->keyBy('language_iso');

        $targetValues = [];
        foreach ($targetLanguages as $language) {
            /** @var Content|null $row */
            $row = $family->get($language);
            $targetValues[$language] = $row
                ? array_map(
                    static fn (array $unit): string => $unit['value'],
                    $this->collectUnits($row->getCurrentContent(), $rootSchema, $includeNonTranslatable),
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
            if (! $includeEmptyUnits && $unit['value'] === '' && $targets === []) {
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
                translatable: (bool) ($unit['translatable'] ?? true),
            );
        }

        return new TranslationDocument(
            contentId: $canonical->id,
            name: (string) $canonical->name,
            slug: (string) $canonical->slug,
            fullSlug: (string) $canonical->full_slug,
            sourceLanguage: $defaultLanguage,
            languages: $targetLanguages,
            units: $units,
        );
    }

    /**
     * Block ids that can carry at least one of the given field keys — the block set
     * the mass-edit grid shows. Matches nested fields too, so a key that only exists
     * inside a `blocks` field is selectable.
     *
     * @param  array<int, string>  $fieldKeys
     * @return array<int, string>
     */
    public function blockIdsWithFields(array $fieldKeys): array
    {
        $wanted = array_flip($fieldKeys);
        $blockIds = [];

        foreach ($this->schemaFieldsByBlock() as $blockId => $fields) {
            if (array_intersect_key($fields, $wanted) !== []) {
                $blockIds[] = $blockId;
            }
        }

        return $blockIds;
    }

    /**
     * Every translatable-capable schema field reachable from each block a content can
     * be built on: its own fields plus everything its nested `blocks` fields can hold.
     * Keyed by block id, then by field key. Memoized — `fields` and `rows` both need
     * the whole map. Nestable-only blocks are reachable but never keys of their own,
     * since no content points at them.
     *
     * Nesting is resolved statically, so an unrestricted `blocks` field is assumed to
     * accept any block. That over-matches (a content may simply not use the nested
     * block), which the grid shows as a document without rows, but never under-matches.
     *
     * @return array<string, array<string, array{type: string, label: string, translatable: bool}>>
     */
    public function schemaFieldsByBlock(): array
    {
        if ($this->schemaFieldsByBlock !== null) {
            return $this->schemaFieldsByBlock;
        }

        $blocks = Block::query()->orderBy('name')->get(['id', 'name', 'slug', 'type', 'schema']);

        /** @var array<string, array<string, mixed>> $schemaBySlug */
        $schemaBySlug = [];
        foreach ($blocks as $block) {
            $schemaBySlug[(string) $block->slug] = $block->schema?->toArray() ?? [];
        }

        $closure = $this->nestingClosure($schemaBySlug);

        $this->schemaFieldsByBlock = [];
        foreach ($blocks as $block) {
            if (! \in_array((string) $block->type, self::CONTENT_BLOCK_TYPES, true)) {
                continue;
            }

            $this->schemaFieldsByBlock[$block->id] = $closure[(string) $block->slug] ?? [];
        }

        return $this->schemaFieldsByBlock;
    }

    /**
     * Fields reachable from each block slug, resolved as a fixed point over the
     * nesting graph rather than by walking paths. Walking is factorial: with N
     * unrestricted `blocks` fields every simple path through the block graph gets
     * visited, and `restrict_blocks` defaults to off, so a normal-sized space is
     * already unrunnable. Iterating to a fixed point yields the same closure,
     * handles cycles without a visited set, and is polynomial.
     *
     * @param  array<string, array<string, mixed>>  $schemaBySlug
     * @return array<string, array<string, array{type: string, label: string, translatable: bool}>>
     */
    private function nestingClosure(array $schemaBySlug): array
    {
        /** @var array<string, array<string, array{type: string, label: string, translatable: bool}>> $fields */
        $fields = [];
        /** @var array<string, array<int, string>> $edges */
        $edges = [];

        foreach ($schemaBySlug as $slug => $schema) {
            [$fields[$slug], $edges[$slug]] = $this->splitSchema($schema, $schemaBySlug);
        }

        // Propagate nested fields upwards until nothing changes. Each round can only
        // add keys or flip translatable false→true, so this always terminates.
        do {
            $changed = false;

            foreach ($edges as $slug => $nested) {
                foreach ($nested as $nestedSlug) {
                    foreach ($fields[$nestedSlug] ?? [] as $key => $field) {
                        $merged = $this->mergeSchemaField($fields[$slug][$key] ?? null, $field);

                        if (($fields[$slug][$key] ?? null) !== $merged) {
                            $fields[$slug][$key] = $merged;
                            $changed = true;
                        }
                    }
                }
            }
        } while ($changed);

        return $fields;
    }

    /**
     * Split one block schema into its own translatable-capable fields and the slugs
     * its `blocks` fields can nest.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $schemaBySlug
     * @return array{0: array<string, array{type: string, label: string, translatable: bool}>, 1: array<int, string>}
     */
    private function splitSchema(array $schema, array $schemaBySlug): array
    {
        $fields = [];
        $nested = [];

        foreach ($schema as $fieldKey => $definition) {
            if (! \is_array($definition)) {
                continue;
            }

            $type = SchemaField::canonicalizeType((string) ($definition['type'] ?? ''));

            if ($type === 'blocks') {
                foreach ($this->nestableSlugs($definition, $schemaBySlug) as $slug) {
                    $nested[$slug] = true;
                }

                continue;
            }

            if (! SchemaField::supportsTranslation($type)) {
                continue;
            }

            $fields[$fieldKey] = $this->mergeSchemaField($fields[$fieldKey] ?? null, [
                'type' => $type,
                'label' => (string) ($definition['name'] ?? $definition['label'] ?? $fieldKey),
                'translatable' => (bool) ($definition['translatable'] ?? false),
            ]);
        }

        return [$fields, array_keys($nested)];
    }

    /**
     * A field key counts as translatable if any block marks it so; that block's
     * label and type also describe the key best.
     *
     * @param  array{type: string, label: string, translatable: bool}|null  $existing
     * @param  array{type: string, label: string, translatable: bool}  $candidate
     * @return array{type: string, label: string, translatable: bool}
     */
    private function mergeSchemaField(?array $existing, array $candidate): array
    {
        if ($existing === null) {
            return $candidate;
        }

        return $candidate['translatable'] && ! $existing['translatable'] ? $candidate : $existing;
    }

    /**
     * Block slugs a `blocks` field can hold. Tag restrictions cannot be resolved from
     * the schema alone, so anything but an explicit block whitelist means "any block".
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $schemaBySlug
     * @return array<int, string>
     */
    private function nestableSlugs(array $definition, array $schemaBySlug): array
    {
        $whitelist = $definition['block_whitelist'] ?? [];

        if (! (bool) ($definition['restrict_blocks'] ?? false) || ! \is_array($whitelist) || $whitelist === []) {
            return array_keys($schemaBySlug);
        }

        return array_values(array_filter(
            array_map(strval(...), $whitelist),
            static fn (string $slug): bool => isset($schemaBySlug[$slug]),
        ));
    }

    /**
     * Collect every translatable unit from a content tree, keyed by stable id.
     *
     * @param  array<string, mixed>  $tree
     * @param  array<string, mixed>  $rootSchema
     * @param  bool  $includeNonTranslatable  Also emit units for supported field types without the translatable flag (marked translatable=false).
     * @return array<string, array{fieldKey: string, type: string, label: string, note: string, path: array<int, string|int>, value: string, translatable: bool}>
     */
    public function collectUnits(array $tree, array $rootSchema, bool $includeNonTranslatable = false): array
    {
        $units = [];
        $this->walk($tree, $rootSchema, '', [], '', $units, $includeNonTranslatable);

        return $units;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, mixed>  $schema
     * @param  array<int, string|int>  $pathPrefix
     * @param  array<string, array<string, mixed>>  $units
     */
    private function walk(array $tree, array $schema, string $idPrefix, array $pathPrefix, string $notePrefix, array &$units, bool $includeNonTranslatable = false): void
    {
        foreach ($schema as $fieldKey => $definition) {
            if (! \is_array($definition)) {
                continue;
            }

            $type = SchemaField::canonicalizeType((string) ($definition['type'] ?? ''));
            $label = (string) ($definition['name'] ?? $definition['label'] ?? $fieldKey);
            $value = $tree[$fieldKey] ?? null;

            if ($type === 'blocks') {
                $this->walkBlocks((array) ($value ?? []), $fieldKey, $label, $idPrefix, $pathPrefix, $notePrefix, $units, $includeNonTranslatable);

                continue;
            }

            if (! SchemaField::supportsTranslation($type)) {
                continue;
            }

            $translatable = (bool) ($definition['translatable'] ?? false);

            if (! $translatable && ! $includeNonTranslatable) {
                continue;
            }

            $id = $idPrefix.$fieldKey;
            $path = [...$pathPrefix, $fieldKey];
            $note = $notePrefix.$label;

            match ($type) {
                'richtext' => $this->emit($units, $id, $fieldKey, $type, $label, $note, $path, $this->richtextToHtml($value), $translatable),
                'meta' => $this->emitMeta($units, $id, $fieldKey, $label, $note, $path, $value, $translatable),
                'table' => $this->emitTable($units, $id, $fieldKey, $label, $note, $path, $value, $definition, $translatable),
                'link' => null, // Links carry no free translatable prose; skipped by design.
                default => $this->emit($units, $id, $fieldKey, $type, $label, $note, $path, $this->scalarToString($value), $translatable),
            };
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, string|int>  $pathPrefix
     * @param  array<string, array<string, mixed>>  $units
     */
    private function walkBlocks(array $items, string $fieldKey, string $label, string $idPrefix, array $pathPrefix, string $notePrefix, array &$units, bool $includeNonTranslatable = false): void
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
                $idPrefix.$fieldKey.'.'.$itemId.'.',
                [...$pathPrefix, $fieldKey, $index],
                $notePrefix.$label.' › ',
                $units,
                $includeNonTranslatable,
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<int, string|int>  $path
     */
    private function emitMeta(array &$units, string $id, string $fieldKey, string $label, string $note, array $path, mixed $value, bool $translatable = true): void
    {
        $meta = \is_array($value) ? $value : [];

        foreach (self::META_KEYS as $metaKey) {
            $this->emit(
                $units,
                $id.'.'.$metaKey,
                $fieldKey,
                'meta',
                $label.' · '.$metaKey,
                $note.' · '.$metaKey,
                [...$path, $metaKey],
                $this->scalarToString($meta[$metaKey] ?? null),
                $translatable,
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<int, string|int>  $path
     * @param  array<string, mixed>  $definition
     */
    private function emitTable(array &$units, string $id, string $fieldKey, string $label, string $note, array $path, mixed $value, array $definition, bool $translatable = true): void
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
                $id.'.header.'.$columnKey,
                $fieldKey,
                'table',
                $label.' · '.$columnKey,
                $note.' · header · '.$columnKey,
                [...$path, 'header', $columnKey],
                $this->scalarToString($header[$columnKey] ?? null),
                $translatable,
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
                    $id.'.rows.'.$rowId.'.'.$columnKey,
                    $fieldKey,
                    'table',
                    $label.' · '.$columnKey,
                    $note.' · row · '.$columnKey,
                    [...$path, 'rows', $index, 'cells', $columnKey],
                    $this->scalarToString($cells[$columnKey] ?? null),
                    $translatable,
                );
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<int, string|int>  $path
     */
    private function emit(array &$units, string $id, string $fieldKey, string $type, string $label, string $note, array $path, string $value, bool $translatable = true): void
    {
        $units[$id] = [
            'fieldKey' => $fieldKey,
            'type' => $type,
            'label' => $label,
            'note' => $note,
            'path' => $path,
            'value' => $value,
            'translatable' => $translatable,
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
