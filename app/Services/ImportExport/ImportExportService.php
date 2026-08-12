<?php

namespace App\Services\ImportExport;

use App\Contracts\ImportExport\ImportExportDriver;
use App\Enums\ImportExportFormat;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use App\Services\ImportExport\Exceptions\InvalidFormatException;

/**
 * @template TDriver of ImportExportDriver
 */
abstract class ImportExportService
{
    /** @var array<string, TDriver> */
    protected array $drivers = [];

    /** @param  TDriver  ...$drivers */
    protected function registerDrivers(ImportExportDriver ...$drivers): void
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->getFormat()] = $driver;
        }
    }

    /** @return TDriver */
    protected function getDriver(ImportExportFormat $format): ImportExportDriver
    {
        if (!isset($this->drivers[$format->value])) {
            throw new InvalidFormatException(
                "Format [{$format->value}] is not supported."
            );
        }

        return $this->drivers[$format->value];
    }

    protected function ensureImportIsValid(callable $validator): void
    {
        $validationErrors = $validator();

        if (!empty($validationErrors)) {
            throw new ImportValidationException(
                'File validation failed: ' . implode(', ', $validationErrors)
            );
        }
    }
}
