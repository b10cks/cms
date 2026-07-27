<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Services\ImportExport\WritesCsvDownload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class CsvAssetDataDriver extends BaseAssetDataDriver
{
    use WritesCsvDownload;

    public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response {
        $headers = $this->mapper->getColumnHeaders($assetFields, $languages);
        $filename = $this->generateFilename($space, 'csv');

        $rows = (function () use ($space, $assets, $languages, $headers): \Generator {
            foreach ($assets as $asset) {
                $rowFields = $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);
                $row = $this->mapper->flattenAsset($asset, $rowFields, $languages);

                yield array_map(static fn ($header) => $row[$header] ?? '', $headers);
            }
        })();

        return $this->csvDownload($headers, $rows, $filename);
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
