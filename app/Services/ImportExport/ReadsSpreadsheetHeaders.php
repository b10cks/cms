<?php

namespace App\Services\ImportExport;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

trait ReadsSpreadsheetHeaders
{
    /**
     * Reads only the first row, so validating a file does not load the whole
     * sheet on top of the load the import itself does.
     *
     * @return list<string>
     */
    protected function spreadsheetHeaders(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === 1;
            }
        });

        $rows = $reader->load($path)->getActiveSheet()->toArray();

        return array_map(static fn (mixed $header): string => trim((string) $header), $rows[0] ?? []);
    }
}
