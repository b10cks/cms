<?php

namespace App\Services\AssetData\Exceptions;

use RuntimeException;
use Throwable;

class AssetDataException extends RuntimeException
{
    public function __construct(
        string $message = 'Asset data operation failed',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
