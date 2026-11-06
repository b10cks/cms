<?php

namespace App\Contracts\RedirectData;

use App\Contracts\ImportExport\ImportExportDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Enumerable;
use Symfony\Component\HttpFoundation\Response;

interface RedirectDataDriver extends ImportExportDriver
{
    public function export(Space $space, Enumerable $redirects): Response;

    /** @return \Generator<int, array{processed: int, total: int}, mixed, ImportResult> */
    public function import(Space $space, UploadedFile $file, RedirectImportMode $mode = RedirectImportMode::Addition): \Generator;

    public function validate(UploadedFile $file): array;
}
