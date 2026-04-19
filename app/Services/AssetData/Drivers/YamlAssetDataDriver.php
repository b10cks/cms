<?php

namespace App\Services\AssetData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

class YamlAssetDataDriver extends BaseAssetDataDriver
{
    public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response {
        $filename = $this->generateFilename($space, 'yaml');

        return new StreamedResponse(function () use ($space, $assets, $assetFields, $languages) {
            $data = [
                'space_id' => $space->id,
                'exported_at' => now()->toIso8601String(),
                'asset_fields' => $assetFields,
                'languages' => $languages,
                'assets' => $assets->map(function ($asset) use ($space, $languages) {
                    $rowFields = $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);

                    return $this->mapper->flattenAsset($asset, $rowFields, $languages);
                })->values()->all(),
            ];

            echo Yaml::dump($data, 10, 2, Yaml::DUMP_OBJECT_AS_MAP);
        }, 200, [
            'Content-Type' => 'application/x-yaml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parseFile(UploadedFile $file): array
    {
        try {
            $content = file_get_contents($file->getRealPath());
            $data = Yaml::parse($content);

            if (!is_array($data) || !isset($data['assets'])) {
                throw new \RuntimeException('YAML must contain an "assets" array');
            }

            if (!is_array($data['assets'])) {
                throw new \RuntimeException('"assets" must be an array');
            }

            return $data['assets'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to parse YAML file: ' . $e->getMessage());
        }
    }

    public function validate(
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): array {
        $errors = [];

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['yaml', 'yml'])) {
            $errors[] = 'File must be a YAML file (yaml, yml)';

            return $errors;
        }

        try {
            $content = file_get_contents($file->getRealPath());
            $data = Yaml::parse($content);

            if (!is_array($data) || !isset($data['assets'])) {
                $errors[] = 'YAML must contain an "assets" array';

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
            $errors[] = 'Unable to read YAML file: ' . $e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::YAML->value;
    }
}
