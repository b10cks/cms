<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\AssetDataFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;

class ExcelAssetDataDriver extends BaseAssetDataDriver
{
    public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response {
        $headers = $this->mapper->getColumnHeaders($assetFields, $languages);
        $filename = $this->generateFilename($space, 'xlsx');

        $export = new class ($assets, $assetFields, $languages, $headers, $this->mapper) implements FromCollection, WithHeadings {
            public function __construct(
            private Collection $assets,
            private array $assetFields,
            private array $languages,
            private array $headers,
            private $mapper
            ) {}

            public function collection(): Collection
            {
                return $this->assets->map(function ($asset) {
                    $row = $this->mapper->flattenAsset($asset, $this->assetFields, $this->languages);

                    return array_map(fn($header) => $row[$header] ?? '', $this->headers);
                });
            }

            public function headings(): array
            {
                return $this->headers;
            }
        };

        return Excel::download($export, $filename);
    }

    public function parseFile(UploadedFile $file): array
    {
        $rows = [];

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $allRows = $sheet->toArray();

            if (count($allRows) <= 1) {
                return [];
            }

            $headers = array_map('trim', $allRows[0] ?? []);

            for ($i = 1; $i < count($allRows); $i++) {
                $rows[] = array_combine($headers, $allRows[$i] ?? []);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to parse Excel file: ' . $e->getMessage());
        }

        return $rows;
    }

    public function validate(
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): array {
        $errors = [];

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'])) {
            $errors[] = 'File must be an Excel file (xlsx, xls) or CSV';

            return $errors;
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            if (empty($rows)) {
                $errors[] = 'Excel file is empty';

                return $errors;
            }

            $headers = array_map('trim', $rows[0] ?? []);

            if (!in_array('id', $headers) && !in_array('filename', $headers)) {
                $errors[] = 'Excel must contain either "id" or "filename" column for asset identification';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unable to read Excel file: ' . $e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return AssetDataFormat::EXCEL->value;
    }
}
