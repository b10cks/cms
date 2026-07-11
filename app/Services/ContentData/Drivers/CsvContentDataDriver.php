<?php

namespace App\Services\ContentData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvContentDataDriver extends BaseContentDataDriver
{
    public function export(Space $space, array $documents): Response
    {
        ['headings' => $headings, 'rows' => $rows] = $this->flatten($documents);
        $filename = $this->generateFilename($space, 'csv');

        return new StreamedResponse(function () use ($headings, $rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headings);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(static fn (string $header): string => (string) ($row[$header] ?? ''), $headings));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
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
