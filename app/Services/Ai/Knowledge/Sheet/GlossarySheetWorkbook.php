<?php

namespace App\Services\Ai\Knowledge\Sheet;

use App\Support\SpreadsheetValue;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The XLSX form of a sheet: every cell an explicit string, so a wording like `1.50`
 * or `TRUE` comes back as written, and a Help sheet that explains each column.
 */
final class GlossarySheetWorkbook
{
    private const HELP = [
        'concept' => 'A stable name you choose for this row, like cloud-bed. It finds the row again when its wording changes. Letters, digits and . _ : - only. Leave it blank and the row matches on its wording in the default language.',
        'keep_exact' => 'yes for a name that stays as written, in exact case, like a product name. A language\'s own term still wins.',
        'note' => 'One short note for the whole row, at most 200 characters. It always reaches the AI.',
        'term:<language>' => 'The wording to write in this language. A wording that belongs to another row is an error, nothing moves on its own.',
        'avoid:<language>' => 'Wording never to write in this language. Separate with a semicolon. End a word with * to cover its longer forms, such as klimaneutral*. With no term in this language, the row is a ban there.',
        'also:<language>' => 'How a source text may also spell this term, such as inflected forms. It counts as the term itself. Separate with a semicolon.',
    ];

    private const RULES = [
        'Rows you leave out stay as they are, and a blank cell keeps its stored value. To remove something, use the glossary editor.',
        'Delete the example row before you import a template.',
    ];

    /**
     * @param  list<string>  $headings
     * @param  iterable<int, list<string>>  $rows
     */
    public function save(array $headings, iterable $rows, string $target): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Glossary');

        $this->writeRow($sheet, 1, $headings);
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(\count($headings)).'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $number = 2;
        foreach ($rows as $row) {
            $this->writeRow($sheet, $number++, $row);
        }

        $this->addHelp($book);
        $book->setActiveSheetIndex(0);

        (new Xlsx($book))->save($target);
        $book->disconnectWorksheets();
    }

    /** @param  list<string>  $cells */
    private function writeRow(Worksheet $sheet, int $number, array $cells): void
    {
        foreach ($cells as $index => $cell) {
            if ($cell !== '') {
                $sheet->setCellValueExplicit([$index + 1, $number], (string) SpreadsheetValue::escape($cell), DataType::TYPE_STRING);
            }
        }
    }

    private function addHelp(Spreadsheet $book): void
    {
        $help = $book->createSheet();
        $help->setTitle('Help');
        $help->setCellValueExplicit('A1', 'Column', DataType::TYPE_STRING);
        $help->setCellValueExplicit('B1', 'What it holds', DataType::TYPE_STRING);
        $help->getStyle('A1:B1')->getFont()->setBold(true);
        $help->getColumnDimension('A')->setWidth(24);
        $help->getColumnDimension('B')->setWidth(100);

        $number = 2;
        foreach (self::HELP as $column => $text) {
            $help->setCellValueExplicit("A{$number}", $column, DataType::TYPE_STRING);
            $help->setCellValueExplicit("B{$number}", $text, DataType::TYPE_STRING);
            $help->getStyle("B{$number}")->getAlignment()->setWrapText(true);
            $number++;
        }

        $number++;
        foreach (self::RULES as $rule) {
            $help->setCellValueExplicit("B{$number}", $rule, DataType::TYPE_STRING);
            $help->getStyle("B{$number}")->getAlignment()->setWrapText(true);
            $number++;
        }
    }
}
