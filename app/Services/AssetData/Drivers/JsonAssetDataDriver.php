<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\AssetDataFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JsonAssetDataDriver extends BaseAssetDataDriver
{
    public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response {
        $filename = $this->generateFilename($space, 'json');

        return new StreamedResponse(function () use ($assets, $assetFields, $languages, $space) {
            $data = [
                'space_id' => $space->id,
                'exported_at' => now()->toIso8601String(),
                'asset_fields' => $assetFields,
                'languages' => $languages,
                'assets' => $assets->map(function ($asset) use ($space, $languages) {
                    $rowFields = $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);

                    return $this->mapper->flattenAsset($asset, $rowFields, $languages);
                })->values(),
            ];

            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
            throw new \RuntimeException('Invalid JSON format: ' . json_last_error_msg());
        }

        if (!is_array($data) || !isset($data['assets'])) {
            throw new \RuntimeException('JSON must contain an "assets" array');
        }

        if (!is_array($data['assets'])) {
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
                $errors[] = 'Invalid JSON format: ' . json_last_error_msg();

                return $errors;
            }

            if (!is_array($data) || !isset($data['assets'])) {
                $errors[] = 'JSON must contain an "assets" array';

                return $errors;
            }

            if (!is_array($data['assets'])) {
                $errors[] = '"assets" must be an array';

                return $errors;
            }

            if (!empty($data['assets'])) {
                $firstAsset = $data['assets'][0];

                if (!isset($firstAsset['id']) && !isset($firstAsset['filename'])) {
                    $errors[] = 'Assets must contain either "id" or "filename" column for asset identification';
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unable to read JSON file: ' . $e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return AssetDataFormat::JSON->value;
    }
}
