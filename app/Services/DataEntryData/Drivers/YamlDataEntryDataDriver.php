<?php

namespace App\Services\DataEntryData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Models\Space\DataSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Enumerable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

class YamlDataEntryDataDriver extends BaseDataEntryDataDriver
{
    public function export(Space $space, DataSource $dataSource, Enumerable $entries): Response
    {
        $filename = $this->generateFilename($space, $dataSource, 'yaml');

        return new StreamedResponse(function () use ($space, $dataSource, $entries) {
            echo Yaml::dump([
                'space_id' => $space->id,
                'data_source_id' => $dataSource->id,
                'data_source_slug' => $dataSource->slug,
                'exported_at' => now()->toIso8601String(),
                'entries' => $entries->map(fn ($entry) => [
                    'id' => $entry->id,
                    'external_id' => $entry->external_id,
                    'key' => $entry->key,
                    'value' => $entry->value,
                    'dimensions' => $entry->dimensions ?? [],
                    'is_active' => $entry->is_active,
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

            if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
                throw new \RuntimeException('YAML must contain an "entries" array');
            }

            return $data['entries'];
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
            $entries = $this->parseFile($file);

            if ($entries !== [] && !array_key_exists('key', $entries[0])) {
                $errors[] = 'YAML entries must contain a "key" field';
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
