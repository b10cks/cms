<?php

namespace App\Services\RedirectData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;

class ExcelRedirectDataDriver extends BaseRedirectDataDriver
{
    private const HEADERS = ['id', 'external_id', 'source', 'target', 'status_code'];

    public function export(Space $space, Collection $redirects): Response
    {
        $filename = $this->generateFilename($space, 'xlsx');

        $export = new class ($redirects) implements FromCollection, WithHeadings {
            public function __construct(
                private readonly Collection $redirects,
            ) {
            }

            public function collection(): Collection
            {
                return $this->redirects->map(fn ($redirect) => [
                    $redirect->id,
                    $redirect->external_id,
                    $redirect->source,
                    $redirect->target,
                    $redirect->status_code,
                ]);
            }

            public function headings(): array
            {
                return ExcelRedirectDataDriver::HEADERS;
            }
        };

        return Excel::download($export, $filename);
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

            if (!in_array('source', $headers, true) || !in_array('target', $headers, true)) {
                $errors[] = 'Excel must contain both "source" and "target" columns';
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
