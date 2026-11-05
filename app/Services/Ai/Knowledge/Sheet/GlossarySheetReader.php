<?php

namespace App\Services\Ai\Knowledge\Sheet;

use App\Support\SpreadsheetValue;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Reads a sheet into plain rows and lets go of the file, so the parser's memory is
 * free again before the glossary loads. A row holds only its non-blank cells and is
 * keyed by its number in the file, header being 1, which is what the person sees.
 *
 * @phpstan-type Sheet array{headers: array<int, string>, rows: array<int, array<int, string>>}
 */
final class GlossarySheetReader
{
    /** @return Sheet */
    public static function read(string $path, string $extension): array
    {
        return $extension === 'xlsx' ? self::readXlsx($path) : self::readCsv($path);
    }

    /** @return Sheet */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['This file could not be read.']]);
        }

        try {
            $first = (string) fgets($handle);
            $hasBom = str_starts_with($first, "\xEF\xBB\xBF");
            $delimiter = self::delimiter($first);
            fseek($handle, $hasBom ? 3 : 0);

            $headers = [];
            $rows = [];
            $number = 0;

            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;
                $row = self::row($cells);

                if ($number === 1) {
                    $headers = $row;
                } elseif ($row !== []) {
                    $rows[$number] = $row;
                }
            }
        } finally {
            fclose($handle);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Excel writes `;` where the decimal separator is a comma. */
    private static function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** @return Sheet */
    private static function readXlsx(string $path): array
    {
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        try {
            // The glossary is the first sheet, wherever the file was last left open. A Help sheet follows it.
            $reader->setLoadSheetsOnly([$reader->listWorksheetNames($path)[0] ?? '']);
            $book = $reader->load($path);
        } catch (ReaderException) {
            throw ValidationException::withMessages(['file' => ['This file could not be read as a spreadsheet.']]);
        }

        $headers = [];
        $rows = [];

        foreach ($book->getSheet(0)->getRowIterator() as $sheetRow) {
            $cells = [];
            $iterator = $sheetRow->getCellIterator();
            $iterator->setIterateOnlyExistingCells(true);

            foreach ($iterator as $column => $cell) {
                $cells[self::columnIndex($column)] = self::cellText($cell);
            }

            $row = self::row($cells);
            $number = $sheetRow->getRowIndex();

            if ($number === 1) {
                $headers = $row;
            } elseif ($row !== []) {
                $rows[$number] = $row;
            }
        }

        $book->disconnectWorksheets();

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function columnIndex(string $column): int
    {
        $index = 0;

        foreach (str_split($column) as $letter) {
            $index = $index * 26 + \ord($letter) - 64;
        }

        return $index - 1;
    }

    /** A formula keeps the result Excel saved. Nothing in an upload gets evaluated. */
    private static function cellText(Cell $cell): string
    {
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_float($value) => rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return array<int, string>
     */
    private static function row(array $cells): array
    {
        $row = [];

        foreach ($cells as $index => $cell) {
            if ($cell === null || $cell === '') {
                continue;
            }

            $text = mb_check_encoding($cell, 'UTF-8') ? $cell : (string) mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
            $text = trim(SpreadsheetValue::unescape($text));

            if ($text !== '') {
                $row[$index] = $text;
            }
        }

        return $row;
    }
}
