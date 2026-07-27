<?php

namespace App\Services\ImportExport;

use App\Support\SpreadsheetValue;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download with every cell escaped against formula injection.
 *
 * The four CSV drivers each opened their own handle and called fputcsv twice,
 * which meant remembering to escape in two places per driver — and all four
 * had remembered for the rows and forgotten for the heading. Going through one
 * writer makes that impossible to get wrong, and a fifth driver inherits it.
 */
trait WritesCsvDownload
{
    /**
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int|string, mixed>>  $rows  Kept lazy: a
     *                                                         generator here
     *                                                         streams rather
     *                                                         than buffering
     *                                                         the export.
     */
    protected function csvDownload(array $headings, iterable $rows, string $filename): Response
    {
        return new StreamedResponse(function () use ($headings, $rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, SpreadsheetValue::escapeRow($headings));

            foreach ($rows as $row) {
                fputcsv($handle, SpreadsheetValue::escapeRow(array_values($row)));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
