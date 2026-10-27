<?php

namespace App\Services\Ai\Run;

/**
 * Normalized usage of one provider call. Cost is null when the provider does not
 * report it. The generation ID lets OpenRouter's figure replace the estimate later.
 */
final readonly class TurnUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $costUsd = null,
        public int $cachedTokens = 0,
        public ?string $generationId = null,
    ) {}
}
