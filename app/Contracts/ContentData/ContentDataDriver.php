<?php

namespace App\Contracts\ContentData;

use App\Contracts\ImportExport\ImportExportDriver;
use App\DTOs\ContentData\TranslationDocument;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

interface ContentDataDriver extends ImportExportDriver
{
    /**
     * @param  array<int, TranslationDocument>  $documents
     */
    public function export(Space $space, array $documents): Response;

    /**
     * Parse an uploaded file into a normalized list of documents to apply.
     *
     * @return array<int, array{content_id: string, targets: array<string, array<string, string>>}>
     */
    public function parse(UploadedFile $file): array;

    /**
     * @return array<int, string>  Validation error messages (empty when valid).
     */
    public function validate(UploadedFile $file): array;

    public function getFormat(): string;
}
