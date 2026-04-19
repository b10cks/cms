<?php

namespace App\Services\RedirectData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JsonRedirectDataDriver extends BaseRedirectDataDriver
{
    public function export(Space $space, Collection $redirects): Response
    {
        $filename = $this->generateFilename($space, 'json');

        return new StreamedResponse(function () use ($space, $redirects) {
            echo json_encode([
                'space_id' => $space->id,
                'exported_at' => now()->toIso8601String(),
                'redirects' => $redirects->map(fn ($redirect) => [
                    'id' => $redirect->id,
                    'external_id' => $redirect->external_id,
                    'source' => $redirect->source,
                    'target' => $redirect->target,
                    'status_code' => $redirect->status_code,
                ])->values(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parseFile(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON format: ' . json_last_error_msg());
        }

        if (!is_array($data) || !isset($data['redirects']) || !is_array($data['redirects'])) {
            throw new \RuntimeException('JSON must contain a "redirects" array');
        }

        return $data['redirects'];
    }

    public function validate(UploadedFile $file): array
    {
        $errors = [];

        if (strtolower($file->getClientOriginalExtension()) !== 'json') {
            $errors[] = 'File must be a JSON file';

            return $errors;
        }

        try {
            $redirects = $this->parseFile($file);

            if ($redirects !== [] && (
                !array_key_exists('source', $redirects[0])
                || !array_key_exists('target', $redirects[0])
            )) {
                $errors[] = 'JSON redirects must contain both "source" and "target" fields';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unable to read JSON file: ' . $e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::JSON->value;
    }
}
