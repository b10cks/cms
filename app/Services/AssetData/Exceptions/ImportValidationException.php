<?php

namespace App\Services\AssetData\Exceptions;

class ImportValidationException extends AssetDataException
{
    public function __construct(
        string $message = 'Import validation failed',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
