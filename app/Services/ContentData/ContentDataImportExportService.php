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
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * @param  array<int, string>|null  $fieldKeys  Restrict exported units to these schema field keys.
     * @param  array<int, string>|null  $languages  Restrict exported targets to these languages.
     * @param  bool  $gridMode  Mirror the mass-edit grid exactly: same block restriction,
     *                          empty units kept (so every listed item exports), non-translatable
     *                          fields included. Off for the classic translation export, which
     *                          only ships units that have something to translate.
     */
    public function exportContents(
        Space $space,
        ImportExportFormat $format,
        ?Filter $filter = null,
        ?array $fieldKeys = null,
        ?array $languages = null,
        bool $gridMode = false,
    ): Response {
        $driver = $this->getDriver($format);

        $query = Content::query()
            ->with('block')
            ->whereNull('i18n_parent_id');

        if ($filter !== null) {
            $query->filter($filter);
        }

        if ($gridMode && $fieldKeys !== null) {
            $query->whereIn('block_id', $this->extractor->blockIdsWithFields($fieldKeys));
        }

        // Tie-break any user sort like the grid does, so export rows line up with
        // what was on screen and batched pages never repeat or skip tied rows.
        $query->orderBy($query->qualifyColumn('id'));

        if ($driver instanceof CsvContentDataDriver) {
            $batches = (function () use ($query, $space, $fieldKeys, $languages, $gridMode): \Generator {
                foreach ($query->lazy(100)->chunk(100) as $contents) {
                    yield $this->extractor->extractForContents(
                        $space,
                        new Collection($contents->all()),
                        $fieldKeys,
                        $languages,
                        includeEmptyUnits: $gridMode,
                        includeNonTranslatable: $gridMode,
                    );
                }
            })();

            return $driver->exportBatches($space, $batches, $gridMode);
        }

        $documents = $this->extractor->extractForContents(
            $space,
            $query->get(),
            $fieldKeys,
            $languages,
            includeEmptyUnits: $gridMode,
            includeNonTranslatable: $gridMode,
        );

        return $driver->export($space, $documents, $gridMode);
    }

    /**
     * @param  bool  $gridMode  The file came from a mass-edit grid export, so it carries
     *                          source-language values and non-translatable fields, and an
     *                          empty cell means "clear this" rather than "not provided".
     */
    public function importContents(
        Space $space,
        UploadedFile $file,
        ImportExportFormat $format,
        ContentTranslationImportMode $mode,
        bool $createMissing,
        Authenticatable $owner,
        bool $gridMode = false,
    ): ImportResult {
        $driver = $this->getDriver($format);

        $this->ensureImportIsValid(fn (): array => $driver->validate($file));

        $documents = $driver->parse($file);

        return $this->applier->apply(
            $space,
            $documents,
            $mode,
            $createMissing,
            $owner,
            allowSourceEdits: $gridMode,
            applyEmpty: $gridMode,
        );
    }
}
