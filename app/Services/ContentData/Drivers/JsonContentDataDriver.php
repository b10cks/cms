<?php

namespace App\Services\ContentData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JsonContentDataDriver extends BaseContentDataDriver
{
    public function export(Space $space, array $documents): Response
    {
        $filename = $this->generateFilename($space, 'json');
        $payload = $this->toStructured($space, $documents);

        return new StreamedResponse(function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parse(UploadedFile $file): array
    {
        $data = json_decode((string) file_get_contents($file->getRealPath()), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON format: ' . json_last_error_msg());
        }

        if (! \is_array($data) || ! isset($data['documents']) || ! \is_array($data['documents'])) {
            throw new \RuntimeException('JSON must contain a "documents" array');
        }

        return $this->parseStructured($data);
    }

    public function validate(UploadedFile $file): array
    {
        if ($error = $this->validateExtension($file->getClientOriginalExtension(), ['json'])) {
            return [$error];
        }

        $data = json_decode((string) file_get_contents($file->getRealPath()), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['Invalid JSON format: ' . json_last_error_msg()];
        }

        if (! \is_array($data) || ! isset($data['documents']) || ! \is_array($data['documents'])) {
            return ['JSON must contain a "documents" array'];
        }

        return [];
    }

    public function getFormat(): string
    {
        return ImportExportFormat::JSON->value;
    }
}
