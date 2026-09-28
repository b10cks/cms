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
    protected function csvDownload(array $headings, iterable $rows, string $filename, bool $spool = false): Response
    {
        $write = static function ($handle) use ($headings, $rows): void {
            fputcsv($handle, SpreadsheetValue::escapeRow($headings), escape: '\\');

            foreach ($rows as $row) {
                fputcsv($handle, SpreadsheetValue::escapeRow(array_values($row)), escape: '\\');
            }

        };

        if ($spool) {
            $handle = fopen('php://temp/maxmemory:2097152', 'w+');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open CSV export spool.');
            }

            try {
                $write($handle);
                rewind($handle);
            } catch (\Throwable $exception) {
                fclose($handle);
                throw $exception;
            }

            $stream = static function () use ($handle): void {
                try {
                    fpassthru($handle);
                } finally {
                    fclose($handle);
                }
            };
        } else {
            $stream = static function () use ($write): void {
                $handle = fopen('php://output', 'w');
                try {
                    $write($handle);
                } finally {
                    fclose($handle);
                }
            };
        }

        return new StreamedResponse($stream, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
