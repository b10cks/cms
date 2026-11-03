<?php

namespace App\Services\AssetData\Drivers;

use App\Contracts\AssetData\AssetDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Asset\AssetMetadataFieldResolver;
use App\Services\AssetData\DataMapper;
use App\Services\ImportExport\BaseImportExportDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseAssetDataDriver extends BaseImportExportDriver implements AssetDataDriver
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $effectiveFieldsByFolder = [];

    public function __construct(
        protected readonly DataMapper $mapper,
        protected readonly AssetMetadataFieldResolver $fieldResolver,
    ) {}

    abstract public function export(
        Space $space,
        iterable $assets,
        array $assetFields,
        array $languages
    ): Response;

    /** @return \Generator<int, array{processed: int, total: int}, mixed, ImportResult> */
    public function import(
        Space $space,
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): \Generator {
        $this->resetState();

        try {
            $validFieldKeys = array_column($assetFields, 'key');
            $validLanguageCodes = array_column($languages, 'code');

            $rows = $this->parseFile($file);
            // Lazy parsers get a second pass to count, instead of holding the file in memory.
            $total = \is_array($rows) ? \count($rows) : iterator_count($this->parseFile($file));

            if ($total === 0) {
                return new ImportResult([], [], [], [['message' => 'File is empty']]);
            }

            $processed = 0;
            $connection = (new Asset)->getConnection();

            yield ['processed' => $processed, 'total' => $total];

            foreach (LazyCollection::make(fn () => $rows)->chunk(static::IMPORT_CHUNK_SIZE) as $chunk) {
                if ($processed === 0) {
                    $this->ignoredFields = $this->detectIgnoredFields(
                        array_keys($chunk->first()),
                        $validFieldKeys,
                        $validLanguageCodes
                    );
                }

                // One broadcast per saved row would flood Reverb and every client
                // on a large file, so rows save muted and one bulk event follows.
                Asset::withoutBroadcasts(fn () => Asset::batchingDeliveryRefresh(fn () => $connection->transaction(function () use ($space, $chunk, $assetFields, $languages): void {
                    $lookup = $this->prefetchAssets($chunk->all());

                    foreach ($chunk as $rowNumber => $rowData) {
                        $this->importRow($space, $rowNumber, $rowData, $assetFields, $languages, $lookup);
                    }
                })));

                $processed += $chunk->count();

                yield ['processed' => $processed, 'total' => $total];
            }

            if ($this->successes !== []) {
                Asset::broadcastBulkChange($space);
            }
        } catch (\Throwable $e) {
            Log::error('File import parsing error', [
                'format' => $this->getFormat(),
                'error' => $e->getMessage(),
            ]);

            return new ImportResult([], [], [], [['message' => 'Failed to parse file: '.$e->getMessage()]]);
        }

        return $this->buildResult();
    }

    protected function importRow(
        Space $space,
        int $rowNumber,
        array $rowData,
        array $assetFields,
        array $languages,
        array &$lookup
    ): void {
        try {
            if (empty($rowData['id']) && empty($rowData['filename'])) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'message' => 'Missing both id and filename - cannot identify asset',
                ];

                return;
            }

            $asset = $this->findAsset($rowData, $lookup);

            if (! $asset) {
                $this->errors[] = [
                    'row' => $rowNumber + 1,
                    'id' => $rowData['id'] ?? $rowData['filename'],
                    'message' => 'Asset not found',
                ];

                return;
            }

            $effectiveFields = $this->effectiveFieldsByFolder[$asset->folder_id ?? '']
                ??= $this->fieldResolver->getEffectiveFieldsForAsset($space, $asset);
            $oldData = $asset->data ?? [];
            $fieldsData = $this->mapper->unflattenRow($rowData, $effectiveFields, $languages);
            $validLanguageCodes = array_column($languages, 'code');

            $this->ignoredFields = array_values(array_unique([
                ...$this->ignoredFields,
                ...$this->detectIgnoredFields(
                    array_keys(array_filter(
                        $rowData,
                        fn (mixed $value): bool => $value !== null && $value !== ''
                    )),
                    array_column($effectiveFields, 'key'),
                    $validLanguageCodes
                ),
            ]));

            $newData = $oldData;
            if ($fieldsData !== []) {
                $newData['fields'] = array_replace_recursive($oldData['fields'] ?? [], $fieldsData);
            }

            $previousFilename = $asset->filename;

            if (isset($rowData['filename']) && $rowData['filename'] !== $asset->filename) {
                $asset->filename = $rowData['filename'];
            }

            $asset->data = $newData;
            $asset->save();

            if ($asset->filename !== $previousFilename) {
                unset($lookup['filename'][mb_strtolower((string) $previousFilename)]);
                $lookup['filename'][mb_strtolower((string) $asset->filename)] = $asset;
            }

            $changeDetails = $this->detectChanges($oldData, $newData);

            if (! empty($changeDetails)) {
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

    /**
     * One query per chunk instead of one per row. Keys are lowercased to match
     * the database's case-insensitive collation; with duplicate filenames the
     * oldest asset wins.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{id: array<string, Asset>, filename: array<string, Asset>}
     */
    protected function prefetchAssets(array $rows): array
    {
        $ids = [];
        $filenames = [];

        foreach ($rows as $row) {
            if (! empty($row['id'])) {
                $ids[] = (string) $row['id'];
            } elseif (! empty($row['filename'])) {
                $filenames[] = (string) $row['filename'];
            }
        }

        $assets = Asset::query()
            ->where(fn ($query) => $query
                ->whereIn('id', $ids)
                // `filename` has no index, so only scan for it when a row needs it.
                ->when($filenames !== [], fn ($query) => $query->orWhereIn('filename', $filenames)))
            ->orderBy('id')
            ->get();

        $lookup = ['id' => [], 'filename' => []];

        foreach ($assets as $asset) {
            $lookup['id'][mb_strtolower($asset->id)] = $asset;
            $lookup['filename'][mb_strtolower((string) $asset->filename)] ??= $asset;
        }

        return $lookup;
    }

    /** @param  array{id: array<string, Asset>, filename: array<string, Asset>}  $lookup */
    protected function findAsset(array $rowData, array $lookup): ?Asset
    {
        if (! empty($rowData['id'])) {
            return $lookup['id'][mb_strtolower((string) $rowData['id'])] ?? null;
        }

        if (! empty($rowData['filename'])) {
            return $lookup['filename'][mb_strtolower((string) $rowData['filename'])] ?? null;
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

                if (! in_array($fieldKey, $validFieldKeys)) {
                    $ignored[] = $header;
                } elseif (! in_array($langCode, $validLanguageCodes)) {
                    $ignored[] = $header;
                }
            } else {
                if (! in_array($header, $validFieldKeys)) {
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
            if (! is_array($fields)) {
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
        return $this->buildExportFilename($space, 'assets', $extension);
    }
}
