<?php

namespace App\Services\DataEntryData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Models\Space\DataSource;
use App\Services\ImportExport\WritesCsvDownload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class CsvDataEntryDataDriver extends BaseDataEntryDataDriver
{
    use WritesCsvDownload;

    public function export(Space $space, DataSource $dataSource, Collection $entries): Response
    {
        $filename = $this->generateFilename($space, $dataSource, 'csv');
        $dimensionColumns = $this->buildDimensionColumns($dataSource);
        $headers = [...self::BASE_COLUMNS, ...$dimensionColumns];

        $rows = (function () use ($entries, $dimensionColumns): \Generator {
            foreach ($entries as $entry) {
                yield $this->buildEntryRow($entry, $dimensionColumns);
            }
        })();

        return $this->csvDownload($headers, $rows, $filename);
    }

    public function parseFile(UploadedFile $file): array
    {
        $rows = [];
        $handle = fopen($file->getRealPath(), 'r');
        $headers = array_map('trim', fgetcsv($handle) ?: []);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                $rows[] = array_combine($headers, $row);
            }
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

        if (! in_array('key', $headers, true)) {
            $errors[] = 'CSV must contain a "key" column';
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::CSV->value;
    }
}
