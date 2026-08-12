<?php

namespace App\Services\ContentData;

use App\Contracts\ContentData\ContentDataDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\ContentTranslationImportMode;
use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\ContentData\Drivers\CsvContentDataDriver;
use App\Services\ContentData\Drivers\ExcelContentDataDriver;
use App\Services\ContentData\Drivers\JsonContentDataDriver;
use App\Services\ContentData\Drivers\XliffContentDataDriver;
use App\Services\ContentData\Drivers\YamlContentDataDriver;
use App\Services\ImportExport\ImportExportService;
use CodersCantina\Filter\Filter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends ImportExportService<ContentDataDriver>
 */
class ContentDataImportExportService extends ImportExportService
{
    public function __construct(
        private readonly ContentTranslationExtractor $extractor,
        private readonly ContentTranslationApplier $applier,
        CsvContentDataDriver $csvDriver,
        ExcelContentDataDriver $excelDriver,
        JsonContentDataDriver $jsonDriver,
        XliffContentDataDriver $xliffDriver,
        YamlContentDataDriver $yamlDriver,
    ) {
        $this->registerDrivers($csvDriver, $excelDriver, $jsonDriver, $xliffDriver, $yamlDriver);
    }

    public function exportContents(Space $space, ImportExportFormat $format, ?Filter $filter = null): Response
    {
        $driver = $this->getDriver($format);

        $query = Content::query()
            ->with('block')
            ->whereNull('i18n_parent_id');

        if ($filter !== null) {
            $query->filter($filter);
        }

        $documents = $this->extractor->extractForContents($space, $query->get());

        return $driver->export($space, $documents);
    }

    public function importContents(
        Space $space,
        UploadedFile $file,
        ImportExportFormat $format,
        ContentTranslationImportMode $mode,
        bool $createMissing,
        Authenticatable $owner,
    ): ImportResult {
        $driver = $this->getDriver($format);

        $this->ensureImportIsValid(fn (): array => $driver->validate($file));

        $documents = $driver->parse($file);

        return $this->applier->apply($space, $documents, $mode, $createMissing, $owner);
    }
}
