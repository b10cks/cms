<?php

namespace App\Services\RedirectData\Drivers;

use App\Contracts\RedirectData\RedirectDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseRedirectDataDriver implements RedirectDataDriver
{
    protected const IMPORTABLE_COLUMNS = [
        'id',
        'external_id',
        'source',
        'target',
        'status_code',
    ];

    protected array $successes = [];
    protected array $changes = [];
    protected array $ignoredFields = [];
    protected array $errors = [];

    abstract public function export(Space $space, Collection $redirects): Response;

    abstract public function parseFile(UploadedFile $file): array;

    public function import(Space $space, UploadedFile $file): ImportResult
    {
        $this->successes = [];
        $this->changes = [];
        $this->ignoredFields = [];
        $this->errors = [];

        try {
            $rows = $this->parseFile($file);

            if (empty($rows)) {
                return new ImportResult([], [], [], [['message' => 'File is empty']]);
            }

            $this->ignoredFields = $this->detectIgnoredFields(
                array_keys($rows[0] ?? [])
            );

            foreach ($rows as $rowNumber => $rowData) {
                $this->importRow($space, $rowNumber, $rowData);
            }
        } catch (\Throwable $e) {
            Log::error('Redirect import parsing error', [
                'format' => $this->getFormat(),
                'error' => $e->getMessage(),
            ]);

            return new ImportResult([], [], [], [['message' => 'Failed to parse file: ' . $e->getMessage()]]);
        }

        return new ImportResult($this->successes, $this->changes, $this->ignoredFields, $this->errors);
    }

    protected function importRow(Space $space, int $rowNumber, array $rowData): void
    {
        try {
            $payload = $this->normalizeRow($rowData);

            if (($payload['source'] ?? null) === null) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'message' => 'Missing required "source" value',
                ];

                return;
            }

            if (($payload['target'] ?? null) === null) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'id' => $payload['id'] ?? $payload['source'],
                    'message' => 'Missing required "target" value',
                ];

                return;
            }

            $statusCode = $payload['status_code'] ?? 301;
            if (!in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'id' => $payload['id'] ?? $payload['source'],
                    'message' => 'Status code must be one of 301, 302, 303, 307, or 308',
                ];

                return;
            }

            $payload['status_code'] = $statusCode;

            $redirect = $this->findRedirect($payload);
            unset($payload['id']);
            $existingValues = $redirect
                ? $this->extractTrackedValues($redirect)
                : [];

            if ($redirect === null) {
                $redirect = new Redirect();
            }

            $redirect->fill($payload);
            $redirect->save();

            $changes = $this->detectChanges($existingValues, $this->extractTrackedValues($redirect));
            if ($changes !== []) {
                $this->changes[] = [
                    'id' => $redirect->id,
                    'source' => $redirect->source,
                    'changes' => $changes,
                ];
            }

            $this->successes[] = [
                'id' => $redirect->id,
                'source' => $redirect->source,
            ];
        } catch (QueryException $e) {
            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $rowData['id'] ?? $rowData['source'] ?? null,
                'message' => $e->getCode() === '23000'
                    ? 'A redirect with this source already exists'
                    : $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('Redirect import error', [
                'row' => $rowNumber + 1,
                'error' => $e->getMessage(),
            ]);

            $this->errors[] = [
                'row' => $rowNumber + 1,
                'id' => $rowData['id'] ?? $rowData['source'] ?? null,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function normalizeRow(array $rowData): array
    {
        $normalized = [];

        foreach (self::IMPORTABLE_COLUMNS as $column) {
            $value = $rowData[$column] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '') {
                $value = null;
            }

            if ($column === 'status_code' && $value !== null) {
                $value = (int) $value;
            }

            if ($value !== null) {
                $normalized[$column] = $value;
            }
        }

        return $normalized;
    }

    protected function findRedirect(array $payload): ?Redirect
    {
        if (!empty($payload['id'])) {
            return Redirect::query()->find($payload['id']);
        }

        if (!empty($payload['external_id'])) {
            $redirect = Redirect::query()
                ->where('external_id', $payload['external_id'])
                ->first();

            if ($redirect !== null) {
                return $redirect;
            }
        }

        if (!empty($payload['source'])) {
            return Redirect::query()
                ->where('source', $payload['source'])
                ->first();
        }

        return null;
    }

    protected function extractTrackedValues(Redirect $redirect): array
    {
        return [
            'external_id' => $redirect->external_id,
            'source' => $redirect->source,
            'target' => $redirect->target,
            'status_code' => $redirect->status_code,
        ];
    }

    protected function detectChanges(array $previous, array $current): array
    {
        $changes = [];

        foreach ($current as $field => $value) {
            $oldValue = $previous[$field] ?? null;

            if ($oldValue !== $value) {
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
                && !in_array($header, self::IMPORTABLE_COLUMNS, true)
        ));
    }

    protected function generateFilename(Space $space, string $extension): string
    {
        $date = now()->format('Y-m-d');

        return "{$space->id}_redirects_{$date}.{$extension}";
    }
}
