<?php

namespace App\Services\DataEntryData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Models\Space\DataSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvDataEntryDataDriver extends BaseDataEntryDataDriver
{
    public function export(Space $space, DataSource $dataSource, Collection $entries): Response
    {
        $filename = $this->generateFilename($space, $dataSource, 'csv');
        $dimensionColumns = $this->buildDimensionColumns($dataSource);
        $headers = [...self::BASE_COLUMNS, ...$dimensionColumns];

        return new StreamedResponse(function () use ($entries, $headers, $dimensionColumns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headers);

            foreach ($entries as $entry) {
                fputcsv($handle, array_values($this->buildEntryRow($entry, $dimensionColumns)));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
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
        if (!$handle) {
            $errors[] = 'Unable to read CSV file';

            return $errors;
        }

        $headers = array_map('trim', fgetcsv($handle) ?: []);
        fclose($handle);

        if (!in_array('key', $headers, true)) {
            $errors[] = 'CSV must contain a "key" column';
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::CSV->value;
    }
}
