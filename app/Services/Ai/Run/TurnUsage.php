<?php

namespace App\Services\Ai\Run;

/** Normalized token usage of one provider call. Cost is null when the provider does not report it. */
final readonly class TurnUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $costUsd = null,
    ) {}
}
