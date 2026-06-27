<?php

namespace App\Services\Ai;

use Carbon\Carbon;

/**
 * Describes the OpenRouter key a space should have, derived from its current
 * subscription and plan. `limit` is a USD spend cap; `unlimited` means no cap
 * (e.g. the Enterprise tier whose plan defines no quotas).
 */
final class AiKeySpec
{
    public function __construct(
        public readonly bool $eligible,
        public readonly bool $unlimited = false,
        public readonly ?float $limit = null,
        public readonly string $limitReset = 'monthly',
        public readonly ?Carbon $expiresAt = null,
    ) {
    }

    public static function ineligible(): self
    {
        return new self(eligible: false);
    }

    /**
     * The limit to send to OpenRouter: null for unlimited or ineligible.
     */
    public function effectiveLimit(): ?float
    {
        return $this->unlimited ? null : $this->limit;
    }
}
