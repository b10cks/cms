<?php

namespace App\Services\Ai\Dto;

use Carbon\Carbon;

/**
 * Provider-agnostic snapshot of a space's AI usage against its quota.
 *
 * `unit` describes what `used`/`limit`/`remaining` are measured in: OpenRouter
 * meters a USD spend cap ('usd'), while token-metered providers would report
 * 'tokens'. `limit === null` together with `unlimited` denotes an uncapped tier.
 * `live` indicates the figures were fetched from the provider in real time as
 * opposed to derived from locally known plan limits.
 */
final class AiUsageDto
{
    public function __construct(
        public readonly string $provider,
        public readonly string $unit,
        public readonly bool $available,
        public readonly bool $unlimited = false,
        public readonly bool $live = false,
        public readonly float $used = 0.0,
        public readonly ?float $limit = null,
        public readonly ?float $remaining = null,
        public readonly ?string $reset = null,
        public readonly ?Carbon $resetsAt = null,
        public readonly ?array $breakdown = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * Usage tracking cannot be reported for this space/provider (e.g. AI
     * disabled, plan without an AI allowance, or a provider that exposes no
     * per-space metering).
     */
    public static function unavailable(string $provider, ?string $message = null, string $unit = 'usd'): self
    {
        return new self(
            provider: $provider,
            unit: $unit,
            available: false,
            message: $message,
        );
    }

    /**
     * An eligible space on an uncapped tier (no spend limit).
     */
    public static function unlimited(string $provider, string $unit, ?string $reset, float $used = 0.0, bool $live = false): self
    {
        return new self(
            provider: $provider,
            unit: $unit,
            available: true,
            unlimited: true,
            live: $live,
            used: $used,
            reset: $reset,
        );
    }

    public function percentage(): int
    {
        if ($this->unlimited || $this->limit === null || $this->limit <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->used / $this->limit) * 100));
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'unit' => $this->unit,
            'available' => $this->available,
            'unlimited' => $this->unlimited,
            'live' => $this->live,
            'used' => round($this->used, 6),
            'limit' => $this->limit === null ? null : round($this->limit, 6),
            'remaining' => $this->remaining === null ? null : round($this->remaining, 6),
            'percentage' => $this->percentage(),
            'reset' => $this->reset,
            'resets_at' => $this->resetsAt?->toIso8601String(),
            'breakdown' => $this->breakdown,
            'message' => $this->message,
        ];
    }
}
