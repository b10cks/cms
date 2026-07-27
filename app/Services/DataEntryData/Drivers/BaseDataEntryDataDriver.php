<?php

namespace App\Services\DataEntryData\Drivers;

use App\Contracts\DataEntryData\DataEntryDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Space\ShapeValue;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseDataEntryDataDriver implements DataEntryDataDriver
{
    protected const BASE_COLUMNS = ['id', 'external_id', 'key', 'value', 'is_active'];

    protected const IMPORT_CHUNK_SIZE = 500;

    protected array $successes = [];
    protected array $changes = [];
    protected array $ignoredFields = [];
    protected array $errors = [];
    protected array $deleted = [];
    protected array $touchedIds = [];

    abstract public function export(Space $space, DataSource $dataSource, Enumerable $entries): Response;

    abstract public function parseFile(UploadedFile $file): array;

    public function import(Space $space, DataSource $dataSource, UploadedFile $file, RedirectImportMode $mode = RedirectImportMode::Addition): ImportResult
    {
        $this->successes = [];
        $this->changes = [];
        $this->ignoredFields = [];
        $this->errors = [];
        $this->deleted = [];
        $this->touchedIds = [];

        try {
            $rows = $this->parseFile($file);

            if (empty($rows)) {
                return new ImportResult([], [], [], [['message' => 'File is empty']]);
            }

            $this->ignoredFields = $this->detectIgnoredFields(array_keys($rows[0] ?? []));

            // Chunked: one transaction and one batched lookup per chunk
            // instead of a per-row SELECT inside one giant transaction. Rows
            // keep going through model saves so audit entries and change
            // tracking stay intact.
            $connection = new DataEntry()->getConnection();

            foreach (array_chunk($rows, self::IMPORT_CHUNK_SIZE, preserve_keys: true) as $chunk) {
                $connection->transaction(function () use ($space, $dataSource, $chunk): void {
                    $lookup = $this->prefetchEntries($dataSource, $chunk);

                    foreach ($chunk as $rowNumber => $rowData) {
                        $this->importRow($space, $dataSource, $rowNumber, $rowData, $lookup);
                    }
                });
            }

            if ($mode === RedirectImportMode::Replacement && $this->errors === []) {
                $this->deleteUntouchedEntries($dataSource);
            }
        } catch (\Throwable $e) {
            Log::error('Data entry import parsing error', [
                'format' => $this->getFormat(),
                'error' => $e->getMessage(),
            ]);

            return new ImportResult([], [], [], [['message' => 'Failed to parse file: ' . $e->getMessage()]]);
        }

        return new ImportResult($this->successes, $this->changes, $this->ignoredFields, $this->errors, $this->deleted);
    }

    protected function deleteUntouchedEntries(DataSource $dataSource): void
    {
        // Diff the id set in PHP instead of a whereNotIn with thousands of
        // bindings; deletions stay per-model so audit entries keep firing.
        $touched = array_fill_keys($this->touchedIds, true);

        $idsToDelete = DataEntry::query()
            ->where('data_source_id', $dataSource->id)
            ->pluck('id')
            ->reject(fn (string $id) => isset($touched[$id]));

        $connection = new DataEntry()->getConnection();

        foreach ($idsToDelete->chunk(self::IMPORT_CHUNK_SIZE) as $ids) {
            $connection->transaction(function () use ($ids): void {
                foreach (DataEntry::query()->whereIn('id', $ids->all())->get() as $entry) {
                    $this->deleted[] = [
                        'id' => $entry->id,
                        'key' => $entry->key,
                    ];
                    $entry->delete();
                }
            });
        }
    }

    /**
     * Batched per-chunk lookup maps so a chunk needs one SELECT instead of
     * one per row. Newly saved entries register themselves so later rows in
     * the same chunk still find them (duplicate keys in one file).
     *
     * @return array{id: array<string, DataEntry>, external_id: array<string, DataEntry>, key: array<string, DataEntry>}
     */
    protected function prefetchEntries(DataSource $dataSource, array $chunk): array
    {
        $ids = [];
        $externalIds = [];
        $keys = [];

        foreach ($chunk as $rowData) {
            $payload = $this->normalizeRow($rowData);

            if (!empty($payload['id'])) {
                $ids[] = $payload['id'];
            }
            if (!empty($payload['external_id'])) {
                $externalIds[] = $payload['external_id'];
            }
            if (!empty($payload['key'])) {
                $keys[] = $payload['key'];
            }
        }

        $lookup = ['id' => [], 'external_id' => [], 'key' => []];

        $entries = DataEntry::query()
            ->where('data_source_id', $dataSource->id)
            ->where(function ($query) use ($ids, $externalIds, $keys): void {
                $query->whereIn('id', $ids)
                    ->orWhereIn('external_id', $externalIds)
                    ->orWhereIn('key', $keys);
            })
            ->get();

        foreach ($entries as $entry) {
            $this->registerEntry($lookup, $entry);
        }

        return $lookup;
    }

    protected function registerEntry(array &$lookup, DataEntry $entry): void
    {
        $lookup['id'][$entry->id] = $entry;

        if ($entry->external_id !== null) {
            $lookup['external_id'][$entry->external_id] = $entry;
        }
        if ($entry->key !== null) {
            $lookup['key'][$entry->key] = $entry;
        }
    }

    protected function importRow(Space $space, DataSource $dataSource, int $rowNumber, array $rowData, array &$lookup): void
    {
        try {
            $payload = $this->normalizeRow($rowData);

            if (($payload['key'] ?? null) === null) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'message' => 'Missing required "key" value',
                ];

                return;
            }

            $payload = $this->applyShapedValues($dataSource, $payload, $rowNumber, $rowData);

            if ($payload === null) {
                return;
            }

            $entry = $this->findEntry($dataSource, $payload, $lookup);
            unset($payload['id']);
            $existingValues = $entry ? $this->extractTrackedValues($entry) : [];

            if ($entry === null) {
                $entry = new DataEntry();
                $entry->data_source_id = $dataSource->id;
            }

            $entry->fill(array_diff_key($payload, ['is_active' => true]));

            if (isset($payload['is_active'])) {
                $entry->is_active = (bool) $payload['is_active'];
            }

            $entry->save();

            $previousKey = $existingValues['key'] ?? null;
            if ($previousKey !== null && $previousKey !== $entry->key) {
                unset($lookup['key'][$previousKey]);
            }
            $this->registerEntry($lookup, $entry);

            $this->touchedIds[] = $entry->id;

            $changes = $this->detectChanges($existingValues, $this->extractTrackedValues($entry));
            if ($changes !== []) {
                $this->changes[] = [
                    'id' => $entry->id,
                    'key' => $entry->key,
                    'changes' => $changes,
                ];
            }

            $this->successes[] = [
                'id' => $entry->id,
                'key' => $entry->key,
            ];
        } catch (QueryException $e) {
            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $rowData['id'] ?? $rowData['key'] ?? null,
                'message' => $e->getCode() === '23000'
                    ? 'A data entry with this key already exists'
                    : $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('Data entry import error', [
                'row' => $rowNumber + 1,
                'error' => $e->getMessage(),
            ]);

            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $rowData['id'] ?? $rowData['key'] ?? null,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function normalizeRow(array $rowData): array
    {
        $normalized = [];

        foreach (self::BASE_COLUMNS as $column) {
            $value = $rowData[$column] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '') {
                $value = null;
            }

            if ($value !== null) {
                $normalized[$column] = $value;
            }
        }

        $dimensions = [];

        foreach ($rowData as $key => $value) {
            if (!str_starts_with($key, 'dimension.')) {
                continue;
            }

            $dimensionKey = substr($key, strlen('dimension.'));

            if ($dimensionKey === '') {
                continue;
            }

            $dimensions[$dimensionKey] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        if ($dimensions !== []) {
            $normalized['dimensions'] = $dimensions;
        } elseif (isset($rowData['dimensions'])) {
            $value = $rowData['dimensions'];

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $normalized['dimensions'] = $decoded;
                }
            } elseif (is_array($value)) {
                $normalized['dimensions'] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Validate and encode imported values against the source's shape.
     * JSON strings (e.g. CSV cells from a shaped export) are decoded first;
     * plain strings stay valid as legacy values. Returns null and records a
     * row error when the value doesn't match the shape.
     */
    protected function applyShapedValues(DataSource $dataSource, array $payload, int $rowNumber, array $rowData): ?array
    {
        if (!$dataSource->hasShape()) {
            return $payload;
        }

        $shape = $dataSource->shape;
        $data = [];
        $rules = [];

        if (array_key_exists('value', $payload)) {
            $data['value'] = $this->decodeShapedInput($payload['value']);
            $rules += ShapeValue::rulesFor($data['value'], $shape, 'value', enforceRequired: true);
        }

        foreach ($payload['dimensions'] ?? [] as $key => $value) {
            $data['dimensions'][$key] = $this->decodeShapedInput($value);
            $rules += ShapeValue::rulesFor($data['dimensions'][$key], $shape, "dimensions.{$key}", enforceRequired: false);
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $rowData['id'] ?? $rowData['key'] ?? null,
                'message' => implode(' ', $validator->errors()->all()),
            ];

            return null;
        }

        if (array_key_exists('value', $data)) {
            $payload['value'] = ShapeValue::encode($data['value'], $shape);
        }

        if (isset($data['dimensions'])) {
            $payload['dimensions'] = array_map(
                fn ($value) => ShapeValue::encode($value, $shape),
                $data['dimensions']
            );
        }

        return $payload;
    }

    protected function decodeShapedInput(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $value;
        }

        return $value;
    }

    /**
     * @param  array{id: array<string, DataEntry>, external_id: array<string, DataEntry>, key: array<string, DataEntry>}  $lookup
     */
    protected function findEntry(DataSource $dataSource, array $payload, array $lookup): ?DataEntry
    {
        if (!empty($payload['id'])) {
            return $lookup['id'][$payload['id']] ?? null;
        }

        if (!empty($payload['external_id'])) {
            $entry = $lookup['external_id'][$payload['external_id']] ?? null;

            if ($entry !== null) {
                return $entry;
            }
        }

        if (!empty($payload['key'])) {
            return $lookup['key'][$payload['key']] ?? null;
        }

        return null;
    }

    protected function extractTrackedValues(DataEntry $entry): array
    {
        return [
            'external_id' => $entry->external_id,
            'key' => $entry->key,
            'value' => $entry->value,
            'dimensions' => $entry->dimensions,
            'is_active' => $entry->is_active,
        ];
    }

    protected function detectChanges(array $previous, array $current): array
    {
        $changes = [];

        foreach ($current as $field => $value) {
            $oldValue = $previous[$field] ?? null;

            $normalizedOld = is_array($oldValue) ? json_encode($oldValue) : $oldValue;
            $normalizedNew = is_array($value) ? json_encode($value) : $value;

            if ($normalizedOld !== $normalizedNew) {
                $changes[] = [
                    'field' => $field,
                    'old' => $oldValue,
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }

    protected function detectIgnoredFields(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            fn (mixed $header): bool => is_string($header)
                && $header !== ''
                && !in_array($header, self::BASE_COLUMNS, true)
                && !str_starts_with($header, 'dimension.')
                && $header !== 'dimensions'
        ));
    }

    protected function buildDimensionColumns(DataSource $dataSource): array
    {
        return array_map(
            fn ($dim) => 'dimension.' . $dim['key'],
            $dataSource->dimensions ?? []
        );
    }

    protected function buildEntryRow(DataEntry $entry, array $dimensionColumns): array
    {
        $row = [
            'id' => $entry->id,
            'external_id' => $entry->external_id,
            'key' => $entry->key,
            'value' => $entry->value,
            'is_active' => $entry->is_active ? '1' : '0',
        ];

        foreach ($dimensionColumns as $col) {
            $dimensionKey = substr($col, strlen('dimension.'));
            $row[$col] = $entry->dimensions[$dimensionKey] ?? null;
        }

        return $row;
    }

    protected function generateFilename(Space $space, DataSource $dataSource, string $extension): string
    {
        $date = now()->format('Y-m-d');
        $slug = str($dataSource->slug)->slug()->value();

        return "{$space->id}_{$slug}_entries_{$date}.{$extension}";
    }
}
