<?php

namespace App\Services\ImportExport;

use App\DTOs\ImportExport\ImportResult;
use Illuminate\Http\UploadedFile;

/**
 * State and result plumbing shared by every import/export driver stack.
 */
abstract class BaseImportExportDriver
{
    use BuildsExportFilename;

    /** Rows per transaction. Progress is reported between chunks, so this also bounds the silence on a stream. */
    protected const IMPORT_CHUNK_SIZE = 100;

    /** Key marking a parsed row that could not be read, holding the reason. */
    protected const MALFORMED_ROW = '__malformed_row';

    protected array $successes = [];

    protected array $changes = [];

    protected array $ignoredFields = [];

    protected array $errors = [];

    /**
     * @return iterable<int, array<string, mixed>>
     */
    abstract public function parseFile(UploadedFile $file): iterable;

    abstract public function getFormat(): string;

    /**
     * Rows of an open CSV file, keyed by their position so later rows keep their
     * number. Blank lines are skipped; a row with the wrong column count stays in
     * the set as a marker, so the importer reports it and carries on.
     *
     * @param  resource  $handle
     * @param  list<string>  $headers
     * @return \Generator<int, array<string, mixed>>
     */
    protected function csvRows($handle, array $headers, string $escape = '\\'): \Generator
    {
        $index = 0;

        while (($row = fgetcsv($handle, escape: $escape)) !== false) {
            if ($row !== [null]) {
                yield $index => \count($row) === \count($headers)
                    ? array_combine($headers, $row)
                    : [self::MALFORMED_ROW => \sprintf('Row has %d columns, expected %d', \count($row), \count($headers))];
            }

            $index++;
        }
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function firstWellFormedRow(iterable $rows): array
    {
        foreach ($rows as $row) {
            if (! isset($row[self::MALFORMED_ROW])) {
                return $row;
            }
        }

        return [];
    }

    protected function resetState(): void
    {
        $this->successes = [];
        $this->changes = [];
        $this->ignoredFields = [];
        $this->errors = [];
    }

    protected function buildResult(): ImportResult
    {
        return new ImportResult($this->successes, $this->changes, $this->ignoredFields, $this->errors);
    }
}
