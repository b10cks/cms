<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JsonAssetDataDriver extends BaseAssetDataDriver
{
    public function export(
        Space $space,
        iterable $assets,
        array $assetFields,
        array $languages
    ): Response {
        $filename = $this->generateFilename($space, 'json');

        return new StreamedResponse(function () use ($assets, $assetFields, $languages, $space) {
            $encode = static fn (mixed $value): string => json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            echo "{\n";
            echo '    "space_id": '.$encode($space->id).",\n";
            echo '    "exported_at": '.$encode(now()->toIso8601String()).",\n";
            echo '    "asset_fields": '.preg_replace('/^/m', '    ', $encode($assetFields)).",\n";
            echo '    "languages": '.preg_replace('/^/m', '    ', $encode($languages)).",\n";
            echo '    "assets": [';

            $first = true;
            foreach ($assets as $asset) {
                $rowFields = $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);
                $row = $this->mapper->flattenAsset($asset, $rowFields, $languages);
                $encoded = $encode($row);

                echo $first ? "\n" : ",\n";
                echo preg_replace('/^/m', '        ', $encoded);
                $first = false;
            }

            echo $first ? "]\n}" : "\n    ]\n}";
        }, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parseFile(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON format: '.json_last_error_msg());
        }

        if (! is_array($data) || ! isset($data['assets'])) {
            throw new \RuntimeException('JSON must contain an "assets" array');
        }

        if (! is_array($data['assets'])) {
            throw new \RuntimeException('"assets" must be an array');
        }

        return $data['assets'];
    }

    public function validate(
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): array {
        $errors = [];

        if ($file->getClientOriginalExtension() !== 'json') {
            $errors[] = 'File must be a JSON file';

            return $errors;
        }

        try {
            $content = file_get_contents($file->getRealPath());
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Invalid JSON format: '.json_last_error_msg();

                return $errors;
            }

            if (! is_array($data) || ! isset($data['assets'])) {
                $errors[] = 'JSON must contain an "assets" array';

                return $errors;
            }

            if (! is_array($data['assets'])) {
                $errors[] = '"assets" must be an array';

                return $errors;
            }

            if (! empty($data['assets'])) {
                $firstAsset = $data['assets'][0];

                if (! isset($firstAsset['id']) && ! isset($firstAsset['filename'])) {
                    $errors[] = 'Assets must contain either "id" or "filename" column for asset identification';
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unable to read JSON file: '.$e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::JSON->value;
    }
}
