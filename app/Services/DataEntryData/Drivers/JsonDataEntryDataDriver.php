<?php

namespace App\Services\DataEntryData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use App\Models\Space\DataSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JsonDataEntryDataDriver extends BaseDataEntryDataDriver
{
    public function export(Space $space, DataSource $dataSource, Collection $entries): Response
    {
        $filename = $this->generateFilename($space, $dataSource, 'json');

        return new StreamedResponse(function () use ($space, $dataSource, $entries) {
            echo json_encode([
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

        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            throw new \RuntimeException('JSON must contain an "entries" array');
        }

        return $data['entries'];
    }

    public function validate(UploadedFile $file): array
    {
        $errors = [];

        if (strtolower($file->getClientOriginalExtension()) !== 'json') {
            $errors[] = 'File must be a JSON file';

            return $errors;
        }

        try {
            $entries = $this->parseFile($file);

            if ($entries !== [] && !array_key_exists('key', $entries[0])) {
                $errors[] = 'JSON entries must contain a "key" field';
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
