<?php

namespace App\Services\AssetData\Drivers;

use App\Contracts\AssetData\AssetDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Asset\AssetMetadataFieldResolver;
use App\Services\AssetData\DataMapper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseAssetDataDriver implements AssetDataDriver
{
    protected array $successes = [];
    protected array $changes = [];
    protected array $ignoredFields = [];
    protected array $errors = [];

    public function __construct(
        protected readonly DataMapper $mapper,
        protected readonly AssetMetadataFieldResolver $fieldResolver,
    ) {
    }

    abstract public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response;

    abstract public function parseFile(UploadedFile $file): array;

    abstract public function getFormat(): string;

    public function import(
        Space $space,
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): ImportResult {
        $this->successes = [];
        $this->changes = [];
        $this->ignoredFields = [];
        $this->errors = [];

        try {
            $rows = $this->parseFile($file);

            if (empty($rows)) {
                return new ImportResult([], [], [], [['message' => 'File is empty']]);
            }

            $validFieldKeys = array_column($assetFields, 'key');
            $validLanguageCodes = array_column($languages, 'code');

            $this->ignoredFields = $this->detectIgnoredFields(
                array_key_first($rows) ? array_keys($rows[0]) : [],
                $validFieldKeys,
                $validLanguageCodes
            );

            foreach ($rows as $rowNumber => $rowData) {
                $this->importRow($space, $rowNumber, $rowData, $assetFields, $languages);
            }
        } catch (\Throwable $e) {
            Log::error('File import parsing error', [
                'format' => $this->getFormat(),
                'error' => $e->getMessage(),
            ]);

            return new ImportResult([], [], [], [['message' => 'Failed to parse file: ' . $e->getMessage()]]);
        }

        return new ImportResult($this->successes, $this->changes, $this->ignoredFields, $this->errors);
    }

    protected function importRow(
        Space $space,
        int $rowNumber,
        array $rowData,
        array $assetFields,
        array $languages
    ): void {
        try {
            if (empty($rowData['id']) && empty($rowData['filename'])) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'message' => 'Missing both id and filename - cannot identify asset',
                ];

                return;
            }

            $asset = $this->findAsset($space, $rowData);

            if (!$asset) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'id' => $rowData['id'] ?? $rowData['filename'],
                    'message' => 'Asset not found',
                ];

                return;
            }

            $effectiveFields = $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);
            $oldData = $asset->data ?? [];
            $fieldsData = $this->mapper->unflattenRow($rowData, $effectiveFields, $languages);
            $validLanguageCodes = array_column($languages, 'code');

            $this->ignoredFields = array_values(array_unique([
                ...$this->ignoredFields,
                ...$this->detectIgnoredFields(
                    array_keys(array_filter(
                        $rowData,
                        fn(mixed $value): bool => $value !== null && $value !== ''
                    )),
                    array_column($effectiveFields, 'key'),
                    $validLanguageCodes
                ),
            ]));

            $newData = $oldData;
            if ($fieldsData !== []) {
                $newData['fields'] = array_replace_recursive($oldData['fields'] ?? [], $fieldsData);
            }

            DB::transaction(function () use ($asset, $rowData, $newData) {
                if (isset($rowData['filename']) && $rowData['filename'] !== $asset->filename) {
                    $asset->filename = $rowData['filename'];
                }

                $asset->data = $newData;
                $asset->save();
            });

            $changeDetails = $this->detectChanges($oldData, $newData);

            if (!empty($changeDetails)) {
                $this->changes[] = [
                    'id' => $asset->id,
                    'filename' => $asset->filename,
                    'changes' => $changeDetails,
                ];
            }

            $this->successes[] = [
                'id' => $asset->id,
                'filename' => $asset->filename,
            ];

        } catch (\Throwable $e) {
            Log::error('Asset import error', [
                'row' => $rowNumber + 1,
                'error' => $e->getMessage(),
            ]);

            $this->errors[] = [
                'row' => $rowNumber + 1,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function findAsset(Space $space, array $rowData): ?Asset
    {
        $query = Asset::query();

        if (!empty($rowData['id'])) {
            return $query->where('id', $rowData['id'])->first();
        }

        if (!empty($rowData['filename'])) {
            return $query->where('filename', $rowData['filename'])->first();
        }

        return null;
    }

    protected function detectIgnoredFields(
        array $headers,
        array $validFieldKeys,
        array $validLanguageCodes
    ): array {
        $ignored = [];
        $systemColumns = ['id', 'filename', 'full_url'];

        foreach ($headers as $header) {
            if (in_array($header, $systemColumns) || $header === null || $header === '') {
                continue;
            }

            if (str_contains($header, '_')) {
                [$fieldKey, $langCode] = explode('_', $header, 2);

                if (!in_array($fieldKey, $validFieldKeys)) {
                    $ignored[] = $header;
                } elseif (!in_array($langCode, $validLanguageCodes)) {
                    $ignored[] = $header;
                }
            } else {
                if (!in_array($header, $validFieldKeys)) {
                    $ignored[] = $header;
                }
            }
        }

        return $ignored;
    }

    protected function detectChanges(array $oldData, array $newData): array
    {
        $changes = [];

        $oldFields = $oldData['fields'] ?? [];
        $newFields = $newData['fields'] ?? [];

        foreach ($newFields as $lang => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $key => $value) {
                $oldValue = $oldFields[$lang][$key] ?? null;

                if ($oldValue !== $value) {
                    $changes[] = [
                        'field' => $key,
                        'language' => $lang,
                        'old' => $oldValue,
                        'new' => $value,
                    ];
                }
            }
        }

        return $changes;
    }

    protected function generateFilename(Space $space, string $extension): string
    {
        $date = now()->format('Y-m-d');

        return "{$space->id}_assets_{$date}.{$extension}";
    }
}
