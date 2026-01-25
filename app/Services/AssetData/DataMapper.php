<?php

namespace App\Services\AssetData;

use App\Models\Space\Asset;
use App\Services\Storage\AssetService;

class DataMapper
{
    public function __construct(
        private readonly AssetService $assetService
    ) {
    }

    public function flattenAsset(
        Asset $asset,
        array $assetFields,
        array $languages
    ): array {
        $flattened = [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'full_url' => $this->assetService->getAssetUrl($asset),
        ];

        $data = $asset->data ?? [];

        foreach ($assetFields as $field) {
            $key = $field['key'];

            $flattened[$key] = data_get($data, "fields._default.$key", null);

            foreach ($languages as $language) {
                $langCode = $language['code'];
                $columnName = "{$key}_{$langCode}";
                $flattened[$columnName] = data_get($data, "fields.$langCode.$key", null);
            }
        }

        return $flattened;
    }

    public function unflattenRow(
        array $row,
        array $assetFields,
        array $languages
    ): array {
        $data = ['_default' => []];

        foreach ($languages as $language) {
            $data[$language['code']] = [];
        }

        foreach ($assetFields as $field) {
            $key = $field['key'];

            if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                $data['_default'][$key] = $row[$key];
            }

            foreach ($languages as $language) {
                $langCode = $language['code'];
                $columnName = "{$key}_{$langCode}";

                if (isset($row[$columnName]) && $row[$columnName] !== null && $row[$columnName] !== '') {
                    $data[$langCode][$key] = $row[$columnName];
                }
            }
        }

        return array_filter($data, fn($lang) => !empty($lang));
    }

    public function getColumnHeaders(array $assetFields, array $languages): array
    {
        $headers = ['id', 'filename', 'full_url'];

        foreach ($assetFields as $field) {
            $key = $field['key'];
            $headers[] = $key;

            foreach ($languages as $language) {
                $headers[] = "{$key}_{$language['code']}";
            }
        }

        return $headers;
    }
}
