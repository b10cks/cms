<?php

namespace App\Services\DataEntryData;

use App\Contracts\DataEntryData\DataEntryDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\ImportExportFormat;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\DataEntryData\Drivers\CsvDataEntryDataDriver;
use App\Services\DataEntryData\Drivers\ExcelDataEntryDataDriver;
use App\Services\DataEntryData\Drivers\JsonDataEntryDataDriver;
use App\Services\DataEntryData\Drivers\YamlDataEntryDataDriver;
use App\Services\ImportExport\ImportExportService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class DataEntryDataImportExportService extends ImportExportService
{
    public function __construct(
        CsvDataEntryDataDriver $csvDriver,
        ExcelDataEntryDataDriver $excelDriver,
        JsonDataEntryDataDriver $jsonDriver,
        YamlDataEntryDataDriver $yamlDriver,
    ) {
        $this->registerDrivers(
            $csvDriver,
            $excelDriver,
            $jsonDriver,
            $yamlDriver,
        );
    }

    public function exportEntries(
        Space $space,
        DataSource $dataSource,
        ImportExportFormat $format,
    ): Response {
        $driver = $this->getDataEntryDriver($format);

        $entries = DataEntry::query()
            ->where('data_source_id', $dataSource->id)
            ->orderBy('key')
            ->get();

        return $driver->export($space, $dataSource, $entries);
    }

    public function importEntries(
        Space $space,
        DataSource $dataSource,
        UploadedFile $file,
        ImportExportFormat $format,
        RedirectImportMode $mode = RedirectImportMode::Addition,
    ): ImportResult {
        $driver = $this->getDataEntryDriver($format);

        $this->ensureImportIsValid(fn (): array => $driver->validate($file));

        return $driver->import($space, $dataSource, $file, $mode);
    }

    protected function getDataEntryDriver(ImportExportFormat $format): DataEntryDataDriver
    {
        /** @var DataEntryDataDriver $driver */
        $driver = $this->getDriver($format);

        return $driver;
    }
}
