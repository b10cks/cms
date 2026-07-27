<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Support\SpreadsheetValue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvAssetDataDriver extends BaseAssetDataDriver
{
    public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response {
        $headers = $this->mapper->getColumnHeaders($assetFields, $languages);
        $filename = $this->generateFilename($space, 'csv');

        return new StreamedResponse(function () use ($space, $assets, $languages, $headers) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headers);

            foreach ($assets as $asset) {
                $rowFields = $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);
                $row = $this->mapper->flattenAsset($asset, $rowFields, $languages);
                $orderedRow = array_map(fn ($header) => $row[$header] ?? '', $headers);
                fputcsv($handle, SpreadsheetValue::escapeRow($orderedRow));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parseFile(UploadedFile $file): array
    {
        $rows = [];
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }

    public function validate(
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): array {
        $errors = [];

        if ($file->getClientOriginalExtension() !== 'csv') {
            $errors[] = 'File must be a CSV file';

            return $errors;
        }

        $handle = @fopen($file->getRealPath(), 'r');

        if (! $handle) {
            $errors[] = 'Unable to read CSV file';

            return $errors;
        }

        $headers = fgetcsv($handle);
        fclose($handle);

        if (! \in_array('id', $headers) && ! \in_array('filename', $headers)) {
            $errors[] = 'CSV must contain either "id" or "filename" column for asset identification';
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::CSV->value;
    }
}
