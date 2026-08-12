<?php

namespace App\Services\AssetData;

use App\Contracts\AssetData\AssetDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\ImportExportFormat;
use App\Http\Filters\Mgmt\AssetFilter;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Asset\AssetMetadataFieldResolver;
use App\Services\AssetData\Drivers\CsvAssetDataDriver;
use App\Services\AssetData\Drivers\ExcelAssetDataDriver;
use App\Services\AssetData\Drivers\JsonAssetDataDriver;
use App\Services\AssetData\Drivers\XliffAssetDataDriver;
use App\Services\AssetData\Drivers\YamlAssetDataDriver;
use App\Services\ImportExport\ImportExportService;
use CodersCantina\Filter\Filter;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends ImportExportService<AssetDataDriver>
 */
class AssetDataExportImportService extends ImportExportService
{
    public function __construct(
        private readonly AssetMetadataFieldResolver $fieldResolver,
        CsvAssetDataDriver $csvDriver,
        ExcelAssetDataDriver $excelDriver,
        JsonAssetDataDriver $jsonDriver,
        XliffAssetDataDriver $xliffDriver,
        YamlAssetDataDriver $yamlDriver,
    ) {
        $this->registerDrivers(
            $csvDriver,
            $excelDriver,
            $jsonDriver,
            $xliffDriver,
            $yamlDriver,
        );
    }

    public function exportAssets(
        Space $space,
        ImportExportFormat $format,
        ?Filter $filter = null
    ): Response {
        $driver = $this->getDriver($format);

        $assetFields = $space->settings->asset_fields ?? [];
        $languages = $space->settings->languages ?? [];

        $query = Asset::with(['folder', 'storage']);

        if ($filter !== null) {
            $query->filter($filter);
        }

        $assets = $query->get();
        $assetFields = $this->fieldResolver->getUnionFieldsForAssets($space, $assets);

        return $driver->export($space, $assets, $assetFields, $languages);
    }

    public function importAssets(
        Space $space,
        UploadedFile $file,
        ImportExportFormat $format
    ): ImportResult {
        $driver = $this->getDriver($format);

        $assetFields = $this->fieldResolver->getAllPossibleFields($space);
        $languages = $space->settings->languages ?? [];

        $this->ensureImportIsValid(fn (): array => $driver->validate($file, $assetFields, $languages));

        return $driver->import($space, $file, $assetFields, $languages);
    }
}
