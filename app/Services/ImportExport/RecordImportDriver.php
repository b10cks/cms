<?php

namespace App\Services\ImportExport;

use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Shared skeleton for the record oriented driver stacks (redirects, data
 * entries): chunked transactional import, batched id/external_id/natural key
 * lookups, change tracking and replacement mode deletion.
 *
 * Subclasses describe their model through {@see importableColumns()},
 * {@see naturalKeyColumn()}, {@see newModel()}, {@see newQuery()},
 * {@see extractTrackedValues()} and {@see importLogLabel()}; everything else is
 * an optional hook.
 */
abstract class RecordImportDriver extends BaseImportExportDriver
{
    protected const IMPORT_CHUNK_SIZE = 500;

    protected array $deleted = [];
    protected array $touchedIds = [];

    /** @return array<int, string> */
    abstract protected function importableColumns(): array;

    /** Column used as the last resort match, e.g. "source" or "key". */
    abstract protected function naturalKeyColumn(): string;

    abstract protected function newModel(): Model;

    abstract protected function newQuery(): Builder;

    abstract protected function extractTrackedValues(Model $record): array;

    /** Human readable subject for log messages, e.g. "Redirect". */
    abstract protected function importLogLabel(): string;

    protected function runImport(Space $space, UploadedFile $file, RedirectImportMode $mode): ImportResult
    {
        $this->resetState();

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
            $connection = $this->newModel()->getConnection();

            $this->withMutedEvents(function () use ($connection, $rows, $mode): void {
                foreach (array_chunk($rows, static::IMPORT_CHUNK_SIZE, preserve_keys: true) as $chunk) {
                    $connection->transaction(function () use ($chunk): void {
                        $lookup = $this->prefetchRecords($chunk);

                        foreach ($chunk as $rowNumber => $rowData) {
                            $this->importRow($rowNumber, $rowData, $lookup);
                        }
                    });
                }

                if ($mode === RedirectImportMode::Replacement && $this->errors === []) {
                    $this->deleteUntouched();
                }
            });

            $this->afterImport($space);
        } catch (\Throwable $e) {
            Log::error($this->importLogLabel() . ' import parsing error', [
                'format' => $this->getFormat(),
                'error' => $e->getMessage(),
            ]);

            return new ImportResult([], [], [], [['message' => 'Failed to parse file: ' . $e->getMessage()]]);
        }

        return $this->buildResult();
    }

    protected function importRow(int $rowNumber, array $rowData, array &$lookup): void
    {
        $naturalKey = $this->naturalKeyColumn();

        try {
            $payload = $this->normalizeRow($rowData);

            if (($payload[$naturalKey] ?? null) === null) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'message' => "Missing required \"{$naturalKey}\" value",
                ];

                return;
            }

            $payload = $this->preparePayload($payload, $rowNumber, $rowData);

            if ($payload === null) {
                return;
            }

            $record = $this->findRecord($payload, $lookup);
            unset($payload['id']);
            $existingValues = $record ? $this->extractTrackedValues($record) : [];

            if ($record === null) {
                $record = $this->newModel();
            }

            $this->fillModel($record, $payload);
            $record->save();

            $previousKey = $existingValues[$naturalKey] ?? null;
            if ($previousKey !== null && $previousKey !== $record->{$naturalKey}) {
                unset($lookup[$naturalKey][$previousKey]);
            }
            $this->registerRecord($lookup, $record);

            $this->touchedIds[] = $record->id;

            $changes = $this->detectChanges($existingValues, $this->extractTrackedValues($record));
            if ($changes !== []) {
                $this->changes[] = [
                    'id' => $record->id,
                    $naturalKey => $record->{$naturalKey},
                    'changes' => $changes,
                ];
            }

            $this->successes[] = [
                'id' => $record->id,
                $naturalKey => $record->{$naturalKey},
            ];
        } catch (QueryException $e) {
            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $rowData['id'] ?? $rowData[$naturalKey] ?? null,
                'message' => $e->getCode() === '23000'
                    ? $this->duplicateKeyMessage()
                    : $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error($this->importLogLabel() . ' import error', [
                'row' => $rowNumber + 1,
                'error' => $e->getMessage(),
            ]);

            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $rowData['id'] ?? $rowData[$naturalKey] ?? null,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Batched per-chunk lookup maps so a chunk needs one SELECT instead of
     * one per row. Newly saved records register themselves so later rows in
     * the same chunk still find them (duplicate natural keys in one file).
     *
     * @return array{id: array<string, Model>, external_id: array<string, Model>, mixed}
     */
    protected function prefetchRecords(array $chunk): array
    {
        $naturalKey = $this->naturalKeyColumn();

        $ids = [];
        $externalIds = [];
        $naturalKeys = [];

        foreach ($chunk as $rowData) {
            $payload = $this->normalizeRow($rowData);

            if (!empty($payload['id'])) {
                $ids[] = $payload['id'];
            }
            if (!empty($payload['external_id'])) {
                $externalIds[] = $payload['external_id'];
            }
            if (!empty($payload[$naturalKey])) {
                $naturalKeys[] = $payload[$naturalKey];
            }
        }

        $lookup = ['id' => [], 'external_id' => [], $naturalKey => []];

        $records = $this->newQuery()
            ->where(function ($query) use ($ids, $externalIds, $naturalKeys, $naturalKey): void {
                $query->whereIn('id', $ids)
                    ->orWhereIn('external_id', $externalIds)
                    ->orWhereIn($naturalKey, $naturalKeys);
            })
            ->get();

        foreach ($records as $record) {
            $this->registerRecord($lookup, $record);
        }

        return $lookup;
    }

    protected function registerRecord(array &$lookup, Model $record): void
    {
        $naturalKey = $this->naturalKeyColumn();

        $lookup['id'][$record->id] = $record;

        if ($record->external_id !== null) {
            $lookup['external_id'][$record->external_id] = $record;
        }
        if ($record->{$naturalKey} !== null) {
            $lookup[$naturalKey][$record->{$naturalKey}] = $record;
        }
    }

    protected function findRecord(array $payload, array $lookup): ?Model
    {
        if (!empty($payload['id'])) {
            return $lookup['id'][$payload['id']] ?? null;
        }

        if (!empty($payload['external_id'])) {
            $record = $lookup['external_id'][$payload['external_id']] ?? null;

            if ($record !== null) {
                return $record;
            }
        }

        $naturalKey = $this->naturalKeyColumn();

        if (!empty($payload[$naturalKey])) {
            return $lookup[$naturalKey][$payload[$naturalKey]] ?? null;
        }

        return null;
    }

    protected function deleteUntouched(): void
    {
        // Diff the id set in PHP instead of a whereNotIn with thousands of
        // bindings; deletions stay per-model so audit entries keep firing.
        $touched = array_fill_keys($this->touchedIds, true);
        $naturalKey = $this->naturalKeyColumn();

        $idsToDelete = $this->newQuery()
            ->pluck('id')
            ->reject(fn (string $id) => isset($touched[$id]));

        $connection = $this->newModel()->getConnection();

        foreach ($idsToDelete->chunk(static::IMPORT_CHUNK_SIZE) as $ids) {
            $connection->transaction(function () use ($ids, $naturalKey): void {
                foreach ($this->newQuery()->whereIn('id', $ids->all())->get() as $record) {
                    $this->deleted[] = [
                        'id' => $record->id,
                        $naturalKey => $record->{$naturalKey},
                    ];
                    $record->delete();
                }
            });
        }
    }

    protected function normalizeRow(array $rowData): array
    {
        $normalized = [];

        foreach ($this->importableColumns() as $column) {
            $value = $rowData[$column] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '') {
                $value = null;
            }

            if ($value !== null) {
                $value = $this->castColumnValue($column, $value);
            }

            if ($value !== null) {
                $normalized[$column] = $value;
            }
        }

        return $this->afterNormalizeRow($normalized, $rowData);
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
                && !$this->isKnownHeader($header)
        ));
    }

    protected function isKnownHeader(string $header): bool
    {
        return in_array($header, $this->importableColumns(), true);
    }

    /**
     * Row level validation and value transformation. Return null to skip the
     * row; implementations record their own error entries.
     */
    protected function preparePayload(array $payload, int $rowNumber, array $rowData): ?array
    {
        return $payload;
    }

    protected function castColumnValue(string $column, mixed $value): mixed
    {
        return $value;
    }

    protected function afterNormalizeRow(array $normalized, array $rowData): array
    {
        return $normalized;
    }

    protected function fillModel(Model $record, array $payload): void
    {
        $record->fill($payload);
    }

    protected function withMutedEvents(callable $callback): void
    {
        $callback();
    }

    protected function afterImport(Space $space): void
    {
    }

    protected function duplicateKeyMessage(): string
    {
        return 'A ' . lcfirst($this->importLogLabel()) . ' with this ' . $this->naturalKeyColumn() . ' already exists';
    }

    protected function resetState(): void
    {
        parent::resetState();

        $this->deleted = [];
        $this->touchedIds = [];
    }

    protected function buildResult(): ImportResult
    {
        return new ImportResult($this->successes, $this->changes, $this->ignoredFields, $this->errors, $this->deleted);
    }
}
