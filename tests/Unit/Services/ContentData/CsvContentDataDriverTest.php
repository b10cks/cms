<?php

namespace Tests\Unit\Services\ContentData;

use App\DTOs\ContentData\TranslationDocument;
use App\DTOs\ContentData\TranslationUnit;
use App\Models\Management\Space;
use App\Services\ContentData\Drivers\CsvContentDataDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CsvContentDataDriverTest extends TestCase
{
    #[Test]
    public function empty_batch_export_keeps_reserved_headers(): void
    {
        $driver = new CsvContentDataDriver;
        $batches = (static function (): \Generator {
            yield from [];
        })();

        ob_start();
        try {
            $driver->exportBatches(new Space, $batches)->sendContent();
            $actual = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame('content_id,content_name,slug,full_slug,unit_id,type,field,source'."\n", $actual);
    }

    #[Test]
    public function batch_export_spools_rows_before_streaming_without_changing_the_csv(): void
    {
        $document = static fn (string $id): TranslationDocument => new TranslationDocument(
            $id, $id, $id, $id, 'en', ['de'], [
                new TranslationUnit('title', 'title', 'text', 'Title', '', ['title'], $id, ['de' => 'Hallo']),
            ],
        );
        $documents = [$document('one'), $document('two'), $document('three')];
        $batchCount = 0;
        $batches = (static function () use ($documents, &$batchCount): \Generator {
            foreach ($documents as $item) {
                $batchCount++;
                yield [$item];
            }
        })();
        $driver = new CsvContentDataDriver;

        $spooled = $driver->exportBatches(new Space, $batches);
        $this->assertSame(3, $batchCount);

        ob_start();
        try {
            $spooled->sendContent();
            $actual = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        ob_start();
        try {
            $driver->export(new Space, $documents)->sendContent();
            $expected = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame($expected, $actual);
    }

    #[Test]
    #[DataProvider('modes')]
    public function it_preserves_language_columns_row_order_and_escaping(bool $gridMode, string $sourceColumn): void
    {
        $documents = [
            new TranslationDocument('one', 'First', 'first', 'first', 'en', ['de'], [
                new TranslationUnit('title', 'title', 'text', 'Title', '', ['title'], '=SUM(1)', ['de' => 'Hallo']),
            ]),
            new TranslationDocument('two', 'Second', 'second', 'second', 'en', ['fr', 'de'], [
                new TranslationUnit('title', 'title', 'text', 'Title', '', ['title'], "Hello,\nworld", ['fr' => 'Bonjour']),
            ]),
        ];
        $driver = new CsvContentDataDriver;
        $response = $driver->export(new Space, $documents, $gridMode);
        ob_start();
        try {
            $response->sendContent();
            $csv = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $this->assertSame(['content_id', 'content_name', 'slug', 'full_slug', 'unit_id', 'type', 'field', $sourceColumn, 'de', 'fr'], fgetcsv($stream, escape: '\\'));
        $this->assertSame(['one', 'First', 'first', 'first', 'title', 'text', 'title', "'=SUM(1)", 'Hallo', ''], fgetcsv($stream, escape: '\\'));
        $this->assertSame(['two', 'Second', 'second', 'second', 'title', 'text', 'title', "Hello,\nworld", '', 'Bonjour'], fgetcsv($stream, escape: '\\'));
        $this->assertFalse(fgetcsv($stream, escape: '\\'));
        fclose($stream);
    }

    public static function modes(): array
    {
        return [[false, 'source'], [true, 'en']];
    }
}
