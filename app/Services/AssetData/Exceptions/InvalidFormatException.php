<?php

namespace App\Services\AssetData\Exceptions;

class InvalidFormatException extends AssetDataException
{
    public function __construct(
        string $message = 'Invalid format specified',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
