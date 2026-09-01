<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Services\ImportExport\WritesCsvDownload;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class CsvAssetDataDriver extends BaseAssetDataDriver
{
    use WritesCsvDownload;

    public function export(
        Space $space,
        iterable $assets,
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

    public function parseFile(UploadedFile $file): iterable
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read CSV file');
        }

        $headers = fgetcsv($handle, escape: '');

        if (! \is_array($headers)) {
            fclose($handle);

            return;
        }

        try {
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $combined = array_combine($headers, $row);

                if ($combined !== false) {
                    yield $combined;
                }
            }
        } finally {
            fclose($handle);
        }
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

        $headers = fgetcsv($handle, escape: '');
        fclose($handle);

        if (! \is_array($headers)) {
            return ['CSV file is empty'];
        }

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
