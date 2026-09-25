<?php

namespace App\Services\ContentData\Drivers;

use App\DTOs\ContentData\TranslationDocument;
use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Services\ImportExport\WritesCsvDownload;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class CsvContentDataDriver extends BaseContentDataDriver
{
    use WritesCsvDownload;

    /**
     * Export extractor batches, which share the space's source and target languages.
     * Consume them before returning so database reads keep their request's tenant.
     *
     * @param  iterable<int, array<int, TranslationDocument>>  $batches
     */
    public function exportBatches(Space $space, iterable $batches, bool $gridMode = false): Response
    {
        $documents = (static function () use ($batches): \Generator {
            foreach ($batches as $batch) {
                yield from $batch;
            }
        })();
        $first = $documents->current();
        $headings = $this->tabularHeadings($first === null ? [] : [$first], $gridMode);
        $rows = (function () use ($documents, $gridMode, $first): \Generator {
            if ($first === null) {
                return;
            }

            foreach ($documents as $document) {
                yield from $this->tabularRows([$document], $gridMode);
            }
        })();

        return $this->csvDownload($headings, $this->orderedRows($rows, $headings), $this->generateFilename($space, 'csv'), spool: true);
    }

    /** Headings are the union of every document's languages. */
    public function export(Space $space, array $documents, bool $gridMode = false): Response
    {
        $headings = $this->tabularHeadings($documents, $gridMode);

        return $this->csvDownload(
            $headings,
            $this->orderedRows($this->tabularRows($documents, $gridMode), $headings),
            $this->generateFilename($space, 'csv'),
        );
    }

    /**
     * @param  iterable<array<string, string>>  $rows
     * @param  array<int, string>  $headings
     * @return \Generator<int, array<int, string>>
     */
    private function orderedRows(iterable $rows, array $headings): \Generator
    {
        foreach ($rows as $row) {
            yield array_map(
                static fn (string $heading): string => (string) ($row[$heading] ?? ''),
                $headings,
            );
        }
    }

    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map(static fn ($header): string => trim((string) $header), $headers);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (\count($row) !== \count($headers)) {
                $row = array_pad(\array_slice($row, 0, \count($headers)), \count($headers), '');
            }

            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $this->parseFlatRows($rows);
    }

    public function validate(UploadedFile $file): array
    {
        if ($error = $this->validateExtension($file->getClientOriginalExtension(), ['csv'])) {
            return [$error];
        }

        $handle = @fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return ['Unable to read CSV file'];
        }

        $headers = fgetcsv($handle);
        fclose($handle);

        $headers = \is_array($headers) ? array_map(static fn ($h): string => trim((string) $h), $headers) : [];

        if (! \in_array('content_id', $headers, true) || ! \in_array('unit_id', $headers, true)) {
            return ['CSV must contain "content_id" and "unit_id" columns'];
        }

        return [];
    }

    public function getFormat(): string
    {
        return ImportExportFormat::CSV->value;
    }
}
