<?php

namespace App\Services\DataEntryData\Drivers;

use App\Contracts\DataEntryData\DataEntryDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Events\Space\DataSourceContentChanged;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\ImportExport\RecordImportDriver;
use App\Services\Space\ShapeValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseDataEntryDataDriver extends RecordImportDriver implements DataEntryDataDriver
{
    protected const BASE_COLUMNS = ['id', 'external_id', 'key', 'value', 'is_active'];

    protected ?DataSource $dataSource = null;

    abstract public function export(Space $space, DataSource $dataSource, Enumerable $entries): Response;

    public function import(Space $space, DataSource $dataSource, UploadedFile $file, RedirectImportMode $mode = RedirectImportMode::Addition): ImportResult
    {
        $this->dataSource = $dataSource;

        return $this->runImport($space, $file, $mode);
    }

    protected function importableColumns(): array
    {
        return self::BASE_COLUMNS;
    }

    protected function naturalKeyColumn(): string
    {
        return 'key';
    }

    protected function importLogLabel(): string
    {
        return 'Data entry';
    }

    protected function newModel(): Model
    {
        $entry = new DataEntry();
        $entry->data_source_id = $this->dataSource->id;

        return $entry;
    }

    protected function newQuery(): Builder
    {
        return DataEntry::query()->where('data_source_id', $this->dataSource->id);
    }

    /**
     * One broadcast per saved row would flood Reverb and every client on a
     * large file — mute the per-model events and stand in for them with a
     * single content-changed event once the import is done.
     */
    protected function withMutedEvents(callable $callback): void
    {
        DataEntry::withoutBroadcasts($callback);
    }

    protected function afterImport(Space $space): void
    {
        if ($this->successes !== [] || $this->deleted !== []) {
            broadcast(new DataSourceContentChanged($space->id, $this->dataSource->id))->toOthers();
        }
    }

    protected function afterNormalizeRow(array $normalized, array $rowData): array
    {
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

    protected function isKnownHeader(string $header): bool
    {
        return parent::isKnownHeader($header)
            || str_starts_with($header, 'dimension.')
            || $header === 'dimensions';
    }

    protected function preparePayload(array $payload, int $rowNumber, array $rowData): ?array
    {
        return $this->applyShapedValues($this->dataSource, $payload, $rowNumber, $rowData);
    }

    protected function fillModel(Model $record, array $payload): void
    {
        $record->fill(array_diff_key($payload, ['is_active' => true]));

        if (isset($payload['is_active'])) {
            $record->is_active = (bool) $payload['is_active'];
        }
    }

    protected function extractTrackedValues(Model $record): array
    {
        return [
            'external_id' => $record->external_id,
            'key' => $record->key,
            'value' => $record->value,
            'dimensions' => $record->dimensions,
            'is_active' => $record->is_active,
        ];
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
        $slug = str($dataSource->slug)->slug()->value();

        return $this->buildExportFilename($space, "{$slug}_entries", $extension);
    }
}
