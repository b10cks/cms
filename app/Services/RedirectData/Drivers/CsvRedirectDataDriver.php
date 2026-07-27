<?php

namespace App\Services\RedirectData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Services\ImportExport\WritesCsvDownload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class CsvRedirectDataDriver extends BaseRedirectDataDriver
{
    use WritesCsvDownload;

    private const HEADERS = ['id', 'external_id', 'source', 'target', 'status_code'];

    public function export(Space $space, Collection $redirects): Response
    {
        $filename = $this->generateFilename($space, 'csv');

        $rows = (function () use ($redirects): \Generator {
            foreach ($redirects as $redirect) {
                yield [
                    $redirect->id,
                    $redirect->external_id,
                    $redirect->source,
                    $redirect->target,
                    $redirect->status_code,
                ];
            }
        })();

        return $this->csvDownload(self::HEADERS, $rows, $filename);
    }

    public function parseFile(UploadedFile $file): array
    {
        $rows = [];
        $handle = fopen($file->getRealPath(), 'r');
        $headers = array_map('trim', fgetcsv($handle) ?: []);

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }

    public function validate(UploadedFile $file): array
    {
        $errors = [];

        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            $errors[] = 'File must be a CSV file';

            return $errors;
        }

        $handle = @fopen($file->getRealPath(), 'r');
        if (! $handle) {
            $errors[] = 'Unable to read CSV file';

            return $errors;
        }

        $headers = array_map('trim', fgetcsv($handle) ?: []);
        fclose($handle);

        if (! in_array('source', $headers, true) || ! in_array('target', $headers, true)) {
            $errors[] = 'CSV must contain both "source" and "target" columns';
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::CSV->value;
    }
}
