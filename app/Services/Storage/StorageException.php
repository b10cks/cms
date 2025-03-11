<?php

namespace App\Services\Storage;

use RuntimeException;
use Throwable;

class StorageException extends RuntimeException
{
    public function __construct(string $message = 'Storage operation failed', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
