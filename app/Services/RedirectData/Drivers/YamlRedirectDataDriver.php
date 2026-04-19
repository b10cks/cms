<?php

namespace App\Services\RedirectData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

class YamlRedirectDataDriver extends BaseRedirectDataDriver
{
    public function export(Space $space, Collection $redirects): Response
    {
        $filename = $this->generateFilename($space, 'yaml');

        return new StreamedResponse(function () use ($space, $redirects) {
            echo Yaml::dump([
                'space_id' => $space->id,
                'exported_at' => now()->toIso8601String(),
                'redirects' => $redirects->map(fn ($redirect) => [
                    'id' => $redirect->id,
                    'external_id' => $redirect->external_id,
                    'source' => $redirect->source,
                    'target' => $redirect->target,
                    'status_code' => $redirect->status_code,
                ])->values()->all(),
            ], 10, 2, Yaml::DUMP_OBJECT_AS_MAP);
        }, 200, [
            'Content-Type' => 'application/x-yaml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parseFile(UploadedFile $file): array
    {
        try {
            $content = file_get_contents($file->getRealPath());
            $data = Yaml::parse($content);

            if (!is_array($data) || !isset($data['redirects']) || !is_array($data['redirects'])) {
                throw new \RuntimeException('YAML must contain a "redirects" array');
            }

            return $data['redirects'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to parse YAML file: ' . $e->getMessage());
        }
    }

    public function validate(UploadedFile $file): array
    {
        $errors = [];
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, ['yaml', 'yml'], true)) {
            $errors[] = 'File must be a YAML file (yaml or yml)';

            return $errors;
        }

        try {
            $redirects = $this->parseFile($file);

            if ($redirects !== [] && (
                !array_key_exists('source', $redirects[0])
                || !array_key_exists('target', $redirects[0])
            )) {
                $errors[] = 'YAML redirects must contain both "source" and "target" fields';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Unable to read YAML file: ' . $e->getMessage();
        }

        return $errors;
    }

    public function getFormat(): string
    {
        return ImportExportFormat::YAML->value;
    }
}
