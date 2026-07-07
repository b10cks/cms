<?php

namespace App\Services\ImportExport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait WritesXlsxDownload
{
    /**
     * Stream the given headings and rows as an xlsx download.
     *
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function xlsxDownload(array $headings, iterable $rows, string $filename): Response
    {
        return new StreamedResponse(function () use ($headings, $rows) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->fromArray($headings, null, 'A1');

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray(array_values($row), null, 'A' . $rowNumber++);
            }

            (new Xlsx($spreadsheet))->save('php://output');

            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
