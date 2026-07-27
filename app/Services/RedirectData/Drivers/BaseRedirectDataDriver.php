<?php

namespace App\Services\RedirectData\Drivers;

use App\Contracts\RedirectData\RedirectDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Enumerable;
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

    protected const IMPORT_CHUNK_SIZE = 500;

    protected array $successes = [];
    protected array $changes = [];
    protected array $ignoredFields = [];
    protected array $errors = [];
    protected array $deleted = [];
    protected array $touchedIds = [];

    abstract public function export(Space $space, Enumerable $redirects): Response;

    abstract public function parseFile(UploadedFile $file): array;

    public function import(Space $space, UploadedFile $file, RedirectImportMode $mode = RedirectImportMode::Addition): ImportResult
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

            $this->ignoredFields = $this->detectIgnoredFields(
                array_keys($rows[0] ?? [])
            );

            // Chunked: one transaction and one batched lookup per chunk
            // instead of a per-row SELECT inside one giant transaction. Rows
            // keep going through model saves so audit entries and change
            // tracking stay intact.
            $connection = new Redirect()->getConnection();

            foreach (array_chunk($rows, self::IMPORT_CHUNK_SIZE, preserve_keys: true) as $chunk) {
                $connection->transaction(function () use ($space, $chunk): void {
                    $lookup = $this->prefetchRedirects($chunk);

                    foreach ($chunk as $rowNumber => $rowData) {
                        $this->importRow($space, $rowNumber, $rowData, $lookup);
                    }
                });
            }

            if ($mode === RedirectImportMode::Replacement && $this->errors === []) {
                $this->deleteUntouchedRedirects($space);
            }
        } catch (\Throwable $e) {
            Log::error('Redirect import parsing error', [
                'format' => $this->getFormat(),
                'error' => $e->getMessage(),
            ]);

            return new ImportResult([], [], [], [['message' => 'Failed to parse file: ' . $e->getMessage()]]);
        }

        return new ImportResult($this->successes, $this->changes, $this->ignoredFields, $this->errors, $this->deleted);
    }

    protected function deleteUntouchedRedirects(Space $space): void
    {
        // Diff the id set in PHP instead of a whereNotIn with thousands of
        // bindings; deletions stay per-model so audit entries keep firing.
        $touched = array_fill_keys($this->touchedIds, true);

        $idsToDelete = Redirect::query()
            ->pluck('id')
            ->reject(fn (string $id) => isset($touched[$id]));

        $connection = new Redirect()->getConnection();

        foreach ($idsToDelete->chunk(self::IMPORT_CHUNK_SIZE) as $ids) {
            $connection->transaction(function () use ($ids): void {
                foreach (Redirect::query()->whereIn('id', $ids->all())->get() as $redirect) {
                    $this->deleted[] = [
                        'id' => $redirect->id,
                        'source' => $redirect->source,
                    ];
                    $redirect->delete();
                }
            });
        }
    }

    /**
     * Batched per-chunk lookup maps so a chunk needs one SELECT instead of
     * one per row. Newly saved redirects register themselves so later rows in
     * the same chunk still find them (duplicate sources in one file).
     *
     * @return array{id: array<string, Redirect>, external_id: array<string, Redirect>, source: array<string, Redirect>}
     */
    protected function prefetchRedirects(array $chunk): array
    {
        $ids = [];
        $externalIds = [];
        $sources = [];

        foreach ($chunk as $rowData) {
            $payload = $this->normalizeRow($rowData);

            if (!empty($payload['id'])) {
                $ids[] = $payload['id'];
            }
            if (!empty($payload['external_id'])) {
                $externalIds[] = $payload['external_id'];
            }
            if (!empty($payload['source'])) {
                $sources[] = $payload['source'];
            }
        }

        $lookup = ['id' => [], 'external_id' => [], 'source' => []];

        $redirects = Redirect::query()
            ->where(function ($query) use ($ids, $externalIds, $sources): void {
                $query->whereIn('id', $ids)
                    ->orWhereIn('external_id', $externalIds)
                    ->orWhereIn('source', $sources);
            })
            ->get();

        foreach ($redirects as $redirect) {
            $this->registerRedirect($lookup, $redirect);
        }

        return $lookup;
    }

    protected function registerRedirect(array &$lookup, Redirect $redirect): void
    {
        $lookup['id'][$redirect->id] = $redirect;

        if ($redirect->external_id !== null) {
            $lookup['external_id'][$redirect->external_id] = $redirect;
        }
        if ($redirect->source !== null) {
            $lookup['source'][$redirect->source] = $redirect;
        }
    }

    protected function importRow(Space $space, int $rowNumber, array $rowData, array &$lookup): void
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

            $redirect = $this->findRedirect($payload, $lookup);
            unset($payload['id']);
            $existingValues = $redirect
                ? $this->extractTrackedValues($redirect)
                : [];

            if ($redirect === null) {
                $redirect = new Redirect();
            }

            $redirect->fill($payload);
            $redirect->save();

            $previousSource = $existingValues['source'] ?? null;
            if ($previousSource !== null && $previousSource !== $redirect->source) {
                unset($lookup['source'][$previousSource]);
            }
            $this->registerRedirect($lookup, $redirect);

            $this->touchedIds[] = $redirect->id;

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

    /**
     * @param  array{id: array<string, Redirect>, external_id: array<string, Redirect>, source: array<string, Redirect>}  $lookup
     */
    protected function findRedirect(array $payload, array $lookup): ?Redirect
    {
        if (!empty($payload['id'])) {
            return $lookup['id'][$payload['id']] ?? null;
        }

        if (!empty($payload['external_id'])) {
            $redirect = $lookup['external_id'][$payload['external_id']] ?? null;

            if ($redirect !== null) {
                return $redirect;
            }
        }

        if (!empty($payload['source'])) {
            return $lookup['source'][$payload['source']] ?? null;
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
