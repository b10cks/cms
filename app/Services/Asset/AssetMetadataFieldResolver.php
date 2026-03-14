<?php

namespace App\Services\Asset;

use App\Models\Management\Space;
use App\Models\Settings;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssetMetadataFieldResolver
{
    /**
     * @var array<string, AssetFolder>
     */
    private array $folderMap = [];

    private bool $folderMapLoaded = false;

    public function getAllPossibleFields(Space $space): array
    {
        $fields = [];

        foreach ($space->settings->asset_fields ?? [] as $field) {
            $normalized = $this->normalizeField($field);
            $fields[$normalized['key']] = $normalized;
        }

        foreach ($this->getFolderMap() as $folder) {
            foreach ($folder->settings->additional_fields ?? [] as $field) {
                $normalized = $this->normalizeField($field);
                $fields[$normalized['key']] = $normalized;
            }
        }

        return array_values($fields);
    }

    public function getEffectiveFieldsForAsset(Space $space, Asset $asset): array
    {
        if ($asset->relationLoaded('folder')) {
            return $this->getEffectiveFieldsForFolder($space, $asset->folder);
        }

        return $this->getEffectiveFieldsForFolder($space, $asset->folder_id);
    }

    public function getEffectiveFieldsForFolder(
        Space $space,
        AssetFolder|string|null $folder = null
    ): array {
        $resolvedFields = [];

        foreach ($space->settings->asset_fields ?? [] as $field) {
            $normalized = $this->normalizeField($field);
            $resolvedFields[$normalized['key']] = [
                ...$normalized,
                'enabled' => true,
            ];
        }

        foreach ($this->getFolderLineage($folder) as $lineageFolder) {
            $this->applyFolderSettings($resolvedFields, $lineageFolder->settings);
        }

        return array_values(array_map(
            fn(array $field): array => Arr::only($field, ['key', 'label', 'required']),
            array_filter($resolvedFields, fn(array $field): bool => $field['enabled'] === true)
        ));
    }

    public function getUnionFieldsForAssets(Space $space, Collection $assets): array
    {
        $fields = [];

        foreach ($assets as $asset) {
            foreach ($this->getEffectiveFieldsForAsset($space, $asset) as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return array_values($fields);
    }

    public function sanitizeFieldData(
        Space $space,
        AssetFolder|string|null $folder,
        array $fieldData
    ): array {
        $allowedFieldKeys = array_column($this->getEffectiveFieldsForFolder($space, $folder), 'key');
        $allowedLanguages = array_merge(
            ['_default'],
            array_values(array_filter(array_map(
                fn(array $language): ?string => $language['code'] ?? null,
                $space->settings->languages ?? []
            )))
        );

        $sanitized = [];

        foreach ($fieldData as $languageCode => $values) {
            if (!\in_array($languageCode, $allowedLanguages, true) || !\is_array($values)) {
                continue;
            }

            $filteredValues = array_filter(
                Arr::only($values, $allowedFieldKeys),
                fn(mixed $value): bool => $value !== null && $value !== ''
            );

            if ($filteredValues !== []) {
                $sanitized[$languageCode] = $filteredValues;
            }
        }

        return $sanitized;
    }

    private function applyFolderSettings(array &$resolvedFields, Settings|array|null $settings): void
    {
        $settingsArray = $settings instanceof Settings ? $settings->toArray() : ($settings ?? []);

        foreach ($settingsArray['additional_fields'] ?? [] as $field) {
            $normalized = $this->normalizeField($field);
            $resolvedFields[$normalized['key']] = [
                ...$normalized,
                'enabled' => true,
            ];
        }

        foreach ($settingsArray['field_overrides'] ?? [] as $override) {
            $fieldKey = data_get($override, 'key');
            if (!\is_string($fieldKey) || !isset($resolvedFields[$fieldKey])) {
                continue;
            }

            if (array_key_exists('enabled', $override) && $override['enabled'] !== null) {
                $resolvedFields[$fieldKey]['enabled'] = (bool) $override['enabled'];
            }

            if (array_key_exists('required', $override) && $override['required'] !== null) {
                $resolvedFields[$fieldKey]['required'] = (bool) $override['required'];
            }
        }
    }

    /**
     * @return array<int, AssetFolder>
     */
    private function getFolderLineage(AssetFolder|string|null $folder): array
    {
        if ($folder instanceof AssetFolder) {
            $currentFolder = $folder;
        } elseif (\is_string($folder) && $folder !== '') {
            $currentFolder = $this->getFolderMap()[$folder] ?? null;
        } else {
            $currentFolder = null;
        }

        $lineage = [];

        while ($currentFolder instanceof AssetFolder) {
            array_unshift($lineage, $currentFolder);

            if (!$currentFolder->parent_id) {
                break;
            }

            $currentFolder = $this->getFolderMap()[$currentFolder->parent_id] ?? null;
        }

        return $lineage;
    }

    /**
     * @return array<string, AssetFolder>
     */
    private function getFolderMap(): array
    {
        if ($this->folderMapLoaded) {
            return $this->folderMap;
        }

        $this->folderMap = AssetFolder::query()
            ->get(['id', 'parent_id', 'settings'])
            ->keyBy('id')
            ->all();
        $this->folderMapLoaded = true;

        return $this->folderMap;
    }

    private function normalizeField(array $field): array
    {
        $key = (string) data_get($field, 'key', '');

        return [
            'key' => $key,
            'label' => (string) data_get($field, 'label', Str::headline($key)),
            'required' => (bool) data_get($field, 'required', false),
        ];
    }
}
