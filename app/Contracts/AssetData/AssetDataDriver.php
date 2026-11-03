<?php

namespace App\Contracts\AssetData;

use App\Contracts\ImportExport\ImportExportDriver;
use App\DTOs\ImportExport\ImportResult;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

interface AssetDataDriver extends ImportExportDriver
{
    public function export(
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
    ): \Generator;

    public function validate(
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): array;

    public function getFormat(): string;
}
