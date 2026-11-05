<?php

namespace App\Services\Ai\Operations;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The scope matches more entries than one operation may freeze. Nothing was cut. */
final class ScopeTooLarge extends RuntimeException
{
    public function __construct(public readonly int $count, public readonly int $limit)
    {
        parent::__construct("The scope has {$count} entries; an operation takes at most {$limit}. Narrow the scope or split it.");
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'reason' => 'scope_too_large',
            'count' => $this->count,
            'limit' => $this->limit,
        ], 422);
    }
}
