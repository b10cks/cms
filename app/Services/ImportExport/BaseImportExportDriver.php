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

    protected array $successes = [];
    protected array $changes = [];
    protected array $ignoredFields = [];
    protected array $errors = [];

    abstract public function parseFile(UploadedFile $file): array;

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
