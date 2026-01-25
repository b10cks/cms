<?php

namespace App\Enums;

enum AssetDataFormat: string
{
    case CSV = 'csv';
    case EXCEL = 'excel';
    case JSON = 'json';
    case XLIFF = 'xliff';

    public function getExtension(): string
    {
        return match ($this) {
            self::CSV => 'csv',
            self::EXCEL => 'xlsx',
            self::JSON => 'json',
            self::XLIFF => 'xlf',
        };
    }

    public function getMimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::JSON => 'application/json',
            self::XLIFF => 'application/x-xliff+xml',
        };
    }
}
