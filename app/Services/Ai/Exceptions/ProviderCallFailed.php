<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A provider call failed. Whether the run service retries depends on
 * {@see $failure}; without one nothing is known, so the run fails.
 */
class ProviderCallFailed extends RuntimeException
{
    public readonly ProviderFailure $failure;

    public function __construct(string $message = '', ?ProviderFailure $failure = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
        $this->failure = $failure ?? new ProviderFailure;
    }
}
