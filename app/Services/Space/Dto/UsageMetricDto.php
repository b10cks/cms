<?php

namespace App\Services\Space\Dto;

/**
 * A single usage dimension (storage, traffic, requests, ai) measured against
 * its plan quota. `unit` is 'bytes' (storage/traffic), 'count' (requests) or
 * 'usd' (ai spend). `limit === null` means the dimension is uncapped/unlimited.
 * `available` is false when the figure could not be metered.
 */
final class UsageMetricDto
{
    public function __construct(
        public readonly string $key,
        public readonly string $unit,
        public readonly float $used,
        public readonly ?float $limit = null,
        public readonly bool $available = true,
    ) {}

    public function unlimited(): bool
    {
        return $this->limit === null;
    }

    public function percentage(): int
    {
        if ($this->limit === null || $this->limit <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->used / $this->limit) * 100));
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'unit' => $this->unit,
            'used' => $this->normalise($this->used),
            'limit' => $this->limit === null ? null : $this->normalise($this->limit),
            'unlimited' => $this->unlimited(),
            'percentage' => $this->percentage(),
            'available' => $this->available,
        ];
    }

    /**
     * USD keeps fractional precision; byte/count metrics are whole numbers.
     */
    private function normalise(float $value): float|int
    {
        return $this->unit === 'usd' ? round($value, 6) : (int) round($value);
    }
}
