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

    public function import(Space $space, UploadedFile $file, RedirectImportMode $mode = RedirectImportMode::Addition): ImportResult;

    public function validate(UploadedFile $file): array;
}
