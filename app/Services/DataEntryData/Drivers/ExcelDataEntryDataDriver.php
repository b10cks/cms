<?php

namespace App\Services\DataEntryData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Models\Space\DataSource;
use App\Services\ImportExport\WritesXlsxDownload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;

class ExcelDataEntryDataDriver extends BaseDataEntryDataDriver
{
    use WritesXlsxDownload;

    public function export(Space $space, DataSource $dataSource, Collection $entries): Response
    {
        $dimensionColumns = $this->buildDimensionColumns($dataSource);
        $headers = [...self::BASE_COLUMNS, ...$dimensionColumns];

        $rows = $entries->map(
            fn ($entry) => array_values($this->buildEntryRow($entry, $dimensionColumns))
        );

        return $this->xlsxDownload($headers, $rows, $this->generateFilename($space, $dataSource, 'xlsx'));
    }

    public function parseFile(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $allRows = $sheet->toArray();

            if (count($allRows) <= 1) {
                return [];
            }

            $headers = array_map('trim', $allRows[0] ?? []);
            $rows = [];

            for ($index = 1; $index < count($allRows); $index++) {
                $rows[] = array_combine($headers, $allRows[$index] ?? []);
            }

            return $rows;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to parse Excel file: ' . $e->getMessage());
        }
    }

    public function validate(UploadedFile $file): array
    {
        $errors = [];
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            $errors[] = 'File must be an Excel file (xlsx or xls)';

            return $errors;
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            $headers = array_map('trim', $rows[0] ?? []);

            if (!in_array('key', $headers, true)) {
                $errors[] = 'Excel must contain a "key" column';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unable to read Excel file: ' . $e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::EXCEL->value;
    }
}
