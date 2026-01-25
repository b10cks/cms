<?php

namespace App\Services\AssetData;

use App\Contracts\AssetData\AssetDataDriver;
use App\DTOs\AssetData\ImportResult;
use App\Enums\AssetDataFormat;
use App\Http\Filters\Mgmt\AssetFilter;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\AssetData\Drivers\CsvAssetDataDriver;
use App\Services\AssetData\Drivers\ExcelAssetDataDriver;
use App\Services\AssetData\Drivers\JsonAssetDataDriver;
use App\Services\AssetData\Drivers\XliffAssetDataDriver;
use App\Services\AssetData\Drivers\YamlAssetDataDriver;
use App\Services\AssetData\Exceptions\InvalidFormatException;
use App\Services\AssetData\Exceptions\ImportValidationException;
use CodersCantina\Filter\Filter;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class AssetDataExportImportService
{
    protected array $drivers = [];

    public function __construct(
        CsvAssetDataDriver $csvDriver,
        ExcelAssetDataDriver $excelDriver,
        JsonAssetDataDriver $jsonDriver,
        XliffAssetDataDriver $xliffDriver,
        YamlAssetDataDriver $yamlDriver,
    ) {
        $this->drivers = [
            AssetDataFormat::CSV->value => $csvDriver,
            AssetDataFormat::EXCEL->value => $excelDriver,
            AssetDataFormat::JSON->value => $jsonDriver,
            AssetDataFormat::XLIFF->value => $xliffDriver,
            AssetDataFormat::YAML->value => $yamlDriver,
        ];
    }

    public function exportAssets(
        Space $space,
        AssetDataFormat $format,
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

        return $driver->export($space, $assets, $assetFields, $languages);
    }

    public function importAssets(
        Space $space,
        UploadedFile $file,
        AssetDataFormat $format
    ): ImportResult {
        $driver = $this->getDriver($format);

        $assetFields = $space->settings->asset_fields ?? [];
        $languages = $space->settings->languages ?? [];

        $validationErrors = $driver->validate($file, $assetFields, $languages);

        if (!empty($validationErrors)) {
            throw new ImportValidationException(
                'File validation failed: ' . implode(', ', $validationErrors)
            );
        }

        return $driver->import($space, $file, $assetFields, $languages);
    }

    protected function getDriver(AssetDataFormat $format): AssetDataDriver
    {
        if (!isset($this->drivers[$format->value])) {
            throw new InvalidFormatException(
                "Format [{$format->value}] is not supported."
            );
        }

        return $this->drivers[$format->value];
    }
}
