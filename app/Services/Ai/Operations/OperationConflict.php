<?php

namespace App\Services\Ai\Operations;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The operation is not in a state that allows this action. Rendered as 409. */
final class OperationConflict extends RuntimeException
{
    /** @param  array<string, mixed>  $details  added to the response body */
    public function __construct(string $message, public readonly string $reason = 'state', public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage(), 'reason' => $this->reason, ...$this->details], 409);
    }
}
