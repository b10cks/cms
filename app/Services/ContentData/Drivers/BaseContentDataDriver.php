<?php

namespace App\Services\ContentData\Drivers;

use App\Contracts\ContentData\ContentDataDriver;
use App\DTOs\ContentData\TranslationDocument;
use App\Models\Management\Space;
use App\Services\ImportExport\BuildsExportFilename;

abstract class BaseContentDataDriver implements ContentDataDriver
{
    use BuildsExportFilename;

    /** Reserved (non-language) column headers used by the flat formats. */
    protected const RESERVED_COLUMNS = ['content_id', 'content_name', 'slug', 'full_slug', 'unit_id', 'type', 'field', 'source'];

    /** Reserved columns of a grid export, which carries the source as a language column. */
    protected const GRID_COLUMNS = ['content_id', 'content_name', 'slug', 'full_slug', 'unit_id', 'type', 'field'];

    protected function generateFilename(Space $space, string $extension): string
    {
        return $this->buildExportFilename($space, 'content_translations', $extension);
    }

    /**
     * Union of all target languages across the given documents, preserving order.
     *
     * @param  array<int, TranslationDocument>  $documents
     * @return array<int, string>
     */
    protected function collectLanguages(array $documents): array
    {
        $languages = [];

        foreach ($documents as $document) {
            foreach ($document->languages as $language) {
                $languages[$language] = true;
            }
        }

        return array_keys($languages);
    }

    /**
     * Flatten documents into ordered header + associative rows for tabular formats.
     *
     * Grid mode swaps the read-only `source` column for a real column named after the
     * source language, so every column is a language and the file imports back
     * symmetrically. The classic translation export keeps `source` as metadata,
     * because that is what translators and their tooling expect.
     *
     * @param  array<int, TranslationDocument>  $documents
     * @return array{headings: array<int, string>, rows: array<int, array<string, string>>}
     */
    protected function flatten(array $documents, bool $gridMode = false): array
    {
        $sourceLanguage = $gridMode ? $this->collectSourceLanguage($documents) : null;
        $languages = $this->collectLanguages($documents);

        $headings = $sourceLanguage !== null
            ? [...self::GRID_COLUMNS, $sourceLanguage, ...$languages]
            : [...self::RESERVED_COLUMNS, ...$languages];

        $rows = [];

        foreach ($documents as $document) {
            foreach ($document->units as $unit) {
                $row = [
                    'content_id' => $document->contentId,
                    'content_name' => $document->name,
                    'slug' => $document->slug,
                    'full_slug' => $document->fullSlug,
                    'unit_id' => $unit->id,
                    'type' => $unit->type,
                    'field' => $unit->fieldKey,
                ];

                if ($sourceLanguage !== null) {
                    $row[$sourceLanguage] = $unit->source;
                } else {
                    $row['source'] = $unit->source;
                }

                foreach ($languages as $language) {
                    $row[$language] = $unit->targets[$language] ?? '';
                }

                $rows[] = $row;
            }
        }

        return ['headings' => $headings, 'rows' => $rows];
    }

    /**
     * The one source language shared by every document in an export (the space
     * default). Null when there is nothing to export.
     *
     * @param  array<int, TranslationDocument>  $documents
     */
    protected function collectSourceLanguage(array $documents): ?string
    {
        foreach ($documents as $document) {
            if ($document->sourceLanguage !== '') {
                return $document->sourceLanguage;
            }
        }

        return null;
    }

    /**
     * Convert flat associative rows (keyed by header) back into documents.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{content_id: string, targets: array<string, array<string, string>>}>
     */
    protected function parseFlatRows(array $rows): array
    {
        $documents = [];

        foreach ($rows as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $contentId = trim((string) ($row['content_id'] ?? ''));
            $unitId = trim((string) ($row['unit_id'] ?? ''));

            if ($contentId === '' || $unitId === '') {
                continue;
            }

            $documents[$contentId] ??= ['content_id' => $contentId, 'targets' => []];

            foreach ($row as $column => $value) {
                if (\in_array($column, self::RESERVED_COLUMNS, true) || $column === null || $column === '') {
                    continue;
                }

                // Empty cells are kept: only the applier knows whether this import
                // may clear values (grid round trip) or must ignore blanks (classic).
                $documents[$contentId]['targets'][(string) $column][$unitId] = (string) ($value ?? '');
            }
        }

        return array_values($documents);
    }

    /**
     * Build the structured payload shared by the JSON and YAML formats.
     *
     * @param  array<int, TranslationDocument>  $documents
     * @return array<string, mixed>
     */
    protected function toStructured(Space $space, array $documents, bool $gridMode = false): array
    {
        return [
            'space_id' => $space->id,
            'exported_at' => now()->toIso8601String(),
            'documents' => array_map(static fn (TranslationDocument $document): array => [
                'content_id' => $document->contentId,
                'name' => $document->name,
                'slug' => $document->slug,
                'full_slug' => $document->fullSlug,
                'source_language' => $document->sourceLanguage,
                'languages' => $document->languages,
                'units' => array_map(static function ($unit) use ($document, $gridMode): array {
                    // Grid mode carries the source inside `targets` so an edited file
                    // imports back symmetrically; see flatten().
                    $targets = $gridMode
                        ? [$document->sourceLanguage => $unit->source, ...$unit->targets]
                        : $unit->targets;

                    return [
                        'id' => $unit->id,
                        'field' => $unit->fieldKey,
                        'type' => $unit->type,
                        'label' => $unit->label,
                        'source' => $unit->source,
                        'targets' => $targets,
                    ];
                }, $document->units),
            ], $documents),
        ];
    }

    /**
     * Parse the structured JSON/YAML payload into documents.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{content_id: string, targets: array<string, array<string, string>>}>
     */
    protected function parseStructured(array $data): array
    {
        $documents = [];

        foreach ($data['documents'] ?? [] as $document) {
            if (! \is_array($document)) {
                continue;
            }

            $contentId = trim((string) ($document['content_id'] ?? ''));
            if ($contentId === '') {
                continue;
            }

            $targets = [];
            foreach ($document['units'] ?? [] as $unit) {
                if (! \is_array($unit)) {
                    continue;
                }

                $unitId = trim((string) ($unit['id'] ?? ''));
                if ($unitId === '') {
                    continue;
                }

                foreach ($unit['targets'] ?? [] as $language => $value) {
                    // Kept even when empty; see parseFlatRows.
                    $targets[(string) $language][$unitId] = (string) ($value ?? '');
                }
            }

            $documents[] = ['content_id' => $contentId, 'targets' => $targets];
        }

        return $documents;
    }

    protected function validateExtension(string $extension, array $allowed): ?string
    {
        if (! \in_array(strtolower($extension), $allowed, true)) {
            return 'File must be one of: '.implode(', ', $allowed);
        }

        return null;
    }
}
