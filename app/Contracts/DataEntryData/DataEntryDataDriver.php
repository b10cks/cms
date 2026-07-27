<?php

namespace App\Contracts\DataEntryData;

use App\Contracts\ImportExport\ImportExportDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use App\Models\Space\DataSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Enumerable;
use Symfony\Component\HttpFoundation\Response;

interface DataEntryDataDriver extends ImportExportDriver
{
    public function export(Space $space, DataSource $dataSource, Enumerable $entries): Response;

    public function import(Space $space, DataSource $dataSource, UploadedFile $file, RedirectImportMode $mode = RedirectImportMode::Addition): ImportResult;

    public function validate(UploadedFile $file): array;
}
