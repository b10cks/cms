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

    protected array $successes = [];

    protected array $changes = [];

    protected array $ignoredFields = [];

    protected array $errors = [];

    /**
     * @return iterable<int, array<string, mixed>>
     */
    abstract public function parseFile(UploadedFile $file): iterable;

    abstract public function getFormat(): string;

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
