<?php

namespace App\Services\RedirectData\Drivers;

use App\Contracts\RedirectData\RedirectDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Services\ImportExport\RecordImportDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Enumerable;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseRedirectDataDriver extends RecordImportDriver implements RedirectDataDriver
{
    protected const IMPORTABLE_COLUMNS = [
        'id',
        'external_id',
        'source',
        'target',
        'status_code',
    ];

    protected const STATUS_CODES = [301, 302, 303, 307, 308];

    abstract public function export(Space $space, Enumerable $redirects): Response;

    public function import(Space $space, UploadedFile $file, RedirectImportMode $mode = RedirectImportMode::Addition): ImportResult
    {
        return $this->runImport($space, $file, $mode);
    }

    protected function importableColumns(): array
    {
        return self::IMPORTABLE_COLUMNS;
    }

    protected function naturalKeyColumn(): string
    {
        return 'source';
    }

    protected function importLogLabel(): string
    {
        return 'Redirect';
    }

    protected function newModel(): Model
    {
        return new Redirect();
    }

    protected function newQuery(): Builder
    {
        return Redirect::query();
    }

    protected function castColumnValue(string $column, mixed $value): mixed
    {
        return $column === 'status_code' ? (int) $value : $value;
    }

    protected function preparePayload(array $payload, int $rowNumber, array $rowData): ?array
    {
        if (($payload['target'] ?? null) === null) {
            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $payload['id'] ?? $payload['source'],
                'message' => 'Missing required "target" value',
            ];

            return null;
        }

        $statusCode = $payload['status_code'] ?? 301;

        if (!in_array($statusCode, self::STATUS_CODES, true)) {
            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $payload['id'] ?? $payload['source'],
                'message' => 'Status code must be one of 301, 302, 303, 307, or 308',
            ];

            return null;
        }

        $payload['status_code'] = $statusCode;

        return $payload;
    }

    protected function extractTrackedValues(Model $record): array
    {
        return [
            'external_id' => $record->external_id,
            'source' => $record->source,
            'target' => $record->target,
            'status_code' => $record->status_code,
        ];
    }

    protected function generateFilename(Space $space, string $extension): string
    {
        return $this->buildExportFilename($space, 'redirects', $extension);
    }
}
