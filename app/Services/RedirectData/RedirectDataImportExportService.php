<?php

namespace App\Services\RedirectData;

use App\Contracts\RedirectData\RedirectDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\ImportExportFormat;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Services\ImportExport\ImportExportService;
use App\Services\RedirectData\Drivers\CsvRedirectDataDriver;
use App\Services\RedirectData\Drivers\ExcelRedirectDataDriver;
use App\Services\RedirectData\Drivers\JsonRedirectDataDriver;
use App\Services\RedirectData\Drivers\YamlRedirectDataDriver;
use CodersCantina\Filter\Filter;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class RedirectDataImportExportService extends ImportExportService
{
    public function __construct(
        CsvRedirectDataDriver $csvDriver,
        ExcelRedirectDataDriver $excelDriver,
        JsonRedirectDataDriver $jsonDriver,
        YamlRedirectDataDriver $yamlDriver,
    ) {
        $this->registerDrivers(
            $csvDriver,
            $excelDriver,
            $jsonDriver,
            $yamlDriver,
        );
    }

    public function exportRedirects(
        Space $space,
        ImportExportFormat $format,
        ?Filter $filter = null,
    ): Response {
        $driver = $this->getRedirectDriver($format);

        $query = Redirect::query();

        if ($filter !== null) {
            $query->filter($filter);
        }

        return $driver->export($space, $query->get());
    }

    public function importRedirects(
        Space $space,
        UploadedFile $file,
        ImportExportFormat $format,
        RedirectImportMode $mode = RedirectImportMode::Addition,
    ): ImportResult {
        $driver = $this->getRedirectDriver($format);

        $this->ensureImportIsValid(fn (): array => $driver->validate($file));

        return $driver->import($space, $file, $mode);
    }

    protected function getRedirectDriver(ImportExportFormat $format): RedirectDataDriver
    {
        /** @var RedirectDataDriver $driver */
        $driver = $this->getDriver($format);

        return $driver;
    }
}
