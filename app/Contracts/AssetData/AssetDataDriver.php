<?php

namespace App\Contracts\AssetData;

use App\DTOs\AssetData\ImportResult;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

interface AssetDataDriver
{
    public function export(
        Space $space,
        Collection $assets,
        array $assetFields,
        array $languages
    ): Response;

    public function import(
        Space $space,
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): ImportResult;

    public function validate(
        UploadedFile $file,
        array $assetFields,
        array $languages
    ): array;

    public function getFormat(): string;
}
