<?php

namespace App\Services\Ai\Exceptions;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * A streamed provider call that failed before its response headers arrived.
 * `reached` is false when the request never left: nothing can have been billed.
 */
final class ProviderTransportException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        public readonly bool $reached,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
