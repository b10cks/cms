<?php

namespace App\Enums;

use InvalidArgumentException;

enum ImportExportFormat: string
{
    case CSV = 'csv';
    case EXCEL = 'excel';
    case JSON = 'json';
    case XLIFF = 'xliff';
    case YAML = 'yaml';

    public static function fromExtension(string $extension): self
    {
        return match (strtolower($extension)) {
            'csv' => self::CSV,
            'xlsx', 'xls' => self::EXCEL,
            'json' => self::JSON,
            'xlf', 'xliff', 'xml' => self::XLIFF,
            'yaml', 'yml' => self::YAML,
            default => throw new InvalidArgumentException(
                "Unsupported file format: .{$extension}. Supported formats: csv, xlsx, xls, json, xlf, xliff, yaml, yml"
            ),
        };
    }

    public function getExtension(): string
    {
        return match ($this) {
            self::CSV => 'csv',
            self::EXCEL => 'xlsx',
            self::JSON => 'json',
            self::XLIFF => 'xlf',
            self::YAML => 'yaml',
        };
    }

    public function getMimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::JSON => 'application/json',
            self::XLIFF => 'application/x-xliff+xml',
            self::YAML => 'application/x-yaml',
        };
    }
}
