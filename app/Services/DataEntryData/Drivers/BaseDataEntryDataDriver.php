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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseDataEntryDataDriver implements DataEntryDataDriver
{
    protected const BASE_COLUMNS = ['id', 'external_id', 'key', 'value', 'is_active'];

    protected array $successes = [];
    protected array $changes = [];
    protected array $ignoredFields = [];
    protected array $errors = [];
    protected array $deleted = [];
    protected array $touchedIds = [];

    abstract public function export(Space $space, DataSource $dataSource, Collection $entries): Response;

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

            DB::transaction(function () use ($space, $dataSource, $rows, $mode): void {
                foreach ($rows as $rowNumber => $rowData) {
                    $this->importRow($space, $dataSource, $rowNumber, $rowData);
                }

                if ($mode === RedirectImportMode::Replacement && $this->errors === []) {
                    $this->deleteUntouchedEntries($dataSource);
                }
            });
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
        $entriesToDelete = DataEntry::query()
            ->where('data_source_id', $dataSource->id)
            ->whereNotIn('id', $this->touchedIds)
            ->get();

        foreach ($entriesToDelete as $entry) {
            $this->deleted[] = [
                'id' => $entry->id,
                'key' => $entry->key,
            ];
            $entry->delete();
        }
    }

    protected function importRow(Space $space, DataSource $dataSource, int $rowNumber, array $rowData): void
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

            $entry = $this->findEntry($dataSource, $payload);
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

    protected function findEntry(DataSource $dataSource, array $payload): ?DataEntry
    {
        if (!empty($payload['id'])) {
            return DataEntry::query()
                ->where('data_source_id', $dataSource->id)
                ->find($payload['id']);
        }

        if (!empty($payload['external_id'])) {
            $entry = DataEntry::query()
                ->where('data_source_id', $dataSource->id)
                ->where('external_id', $payload['external_id'])
                ->first();

            if ($entry !== null) {
                return $entry;
            }
        }

        if (!empty($payload['key'])) {
            return DataEntry::query()
                ->where('data_source_id', $dataSource->id)
                ->where('key', $payload['key'])
                ->first();
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
