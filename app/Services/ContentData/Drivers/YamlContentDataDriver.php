<?php

namespace App\Services\ContentData\Drivers;

use App\Enums\ImportExportFormat;
use App\Models\Management\Space;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

class YamlContentDataDriver extends BaseContentDataDriver
{
    public function export(Space $space, array $documents, bool $gridMode = false): Response
    {
        $filename = $this->generateFilename($space, 'yaml');
        $payload = Yaml::dump($this->toStructured($space, $documents, $gridMode), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        return new StreamedResponse(function () use ($payload): void {
            echo $payload;
        }, 200, [
            'Content-Type' => 'application/x-yaml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function parse(UploadedFile $file): array
    {
        try {
            $data = Yaml::parseFile($file->getRealPath());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid YAML format: ' . $e->getMessage());
        }

        if (! \is_array($data) || ! isset($data['documents']) || ! \is_array($data['documents'])) {
            throw new \RuntimeException('YAML must contain a "documents" list');
        }

        return $this->parseStructured($data);
    }

    public function validate(UploadedFile $file): array
    {
        if ($error = $this->validateExtension($file->getClientOriginalExtension(), ['yaml', 'yml'])) {
            return [$error];
        }

        try {
            $data = Yaml::parseFile($file->getRealPath());
        } catch (\Throwable $e) {
            return ['Invalid YAML format: ' . $e->getMessage()];
        }

        if (! \is_array($data) || ! isset($data['documents']) || ! \is_array($data['documents'])) {
            return ['YAML must contain a "documents" list'];
        }

        return [];
    }

    public function getFormat(): string
    {
        return ImportExportFormat::YAML->value;
    }
}
