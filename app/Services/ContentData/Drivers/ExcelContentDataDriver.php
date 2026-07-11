<?php

namespace App\Services\ContentData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Services\ImportExport\WritesXlsxDownload;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;

class ExcelContentDataDriver extends BaseContentDataDriver
{
    use WritesXlsxDownload;

    public function export(Space $space, array $documents): Response
    {
        ['headings' => $headings, 'rows' => $rows] = $this->flatten($documents);

        $orderedRows = array_map(
            static fn (array $row): array => array_map(static fn (string $header): string => (string) ($row[$header] ?? ''), $headings),
            $rows,
        );

        return $this->xlsxDownload($headings, $orderedRows, $this->generateFilename($space, 'xlsx'));
    }

    public function parse(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $allRows = $spreadsheet->getActiveSheet()->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to parse Excel file: ' . $e->getMessage());
        }

        if (\count($allRows) <= 1) {
            return [];
        }

        $headers = array_map(static fn ($header): string => trim((string) $header), $allRows[0] ?? []);
        $rows = [];

        for ($i = 1, $count = \count($allRows); $i < $count; $i++) {
            $values = array_pad(\array_slice($allRows[$i] ?? [], 0, \count($headers)), \count($headers), '');
            $rows[] = array_combine($headers, $values);
        }

        return $this->parseFlatRows($rows);
    }

    public function validate(UploadedFile $file): array
    {
        if ($error = $this->validateExtension($file->getClientOriginalExtension(), ['xlsx', 'xls'])) {
            return [$error];
        }

        try {
            $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray();
        } catch (\Throwable $e) {
            return ['Unable to read Excel file: ' . $e->getMessage()];
        }

        if (empty($rows)) {
            return ['Excel file is empty'];
        }

        $headers = array_map(static fn ($header): string => trim((string) $header), $rows[0] ?? []);

        if (! \in_array('content_id', $headers, true) || ! \in_array('unit_id', $headers, true)) {
            return ['Excel must contain "content_id" and "unit_id" columns'];
        }

        return [];
    }

    public function getFormat(): string
    {
        return ImportExportFormat::EXCEL->value;
    }
}
