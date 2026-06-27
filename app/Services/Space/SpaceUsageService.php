<?php

namespace App\Services\Space;

use App\Models\Management\Space;
use App\Models\Management\SpaceApiHitHourly;
use App\Models\Management\SpaceTrafficUsageHourly;
use App\Models\Space\Asset;
use App\Services\Ai\SpaceAiUsageService;
use App\Services\Space\Dto\UsageMetricDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates a space's consumption for each plan quota dimension (storage,
 * traffic, requests, AI spend) measured against the limits of the space's
 * current subscription. Traffic/requests are summed over the current calendar
 * month (matching the "per month" quotas); storage is a live footprint; AI
 * spend is delegated to {@see SpaceAiUsageService} (live OpenRouter usage).
 */
class SpaceUsageService
{
    /** Cache TTL for the storage footprint sum, in seconds. */
    private const STORAGE_CACHE_TTL = 60;

    public function __construct(
        private readonly SpaceAiUsageService $aiUsage,
    ) {}

    /**
     * @return array{storage: UsageMetricDto, traffic: UsageMetricDto, requests: UsageMetricDto, ai: UsageMetricDto, period: array{start: string, end: string, resets_at: string}}
     */
    public function forSpace(Space $space): array
    {
        $subscription = $space->resolveCurrentSubscription();
        // Subscription snapshot wins over the plan definition. null = unlimited
        // (or no subscription), so per-dimension limits resolve to null too.
        $quotas = $subscription ? ($subscription->quotas ?? $subscription->plan?->quotas) : null;

        $periodStart = now()->startOfMonth();
        $periodEnd = now();

        return [
            'storage' => $this->storage($space, $this->quota($quotas, 'storage')),
            'traffic' => $this->traffic($space, $periodStart, $periodEnd, $this->quota($quotas, 'traffic')),
            'requests' => $this->requests($space, $periodStart, $periodEnd, $this->quota($quotas, 'requests')),
            'ai' => $this->ai($space, $this->quota($quotas, 'aiCredit')),
            'period' => [
                'start' => $periodStart->toIso8601String(),
                'end' => $periodEnd->toIso8601String(),
                'resets_at' => now()->startOfMonth()->addMonth()->toIso8601String(),
            ],
        ];
    }

    private function quota(?array $quotas, string $key): ?float
    {
        $value = $quotas[$key] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * Live storage footprint (bytes). Point-in-time, cached briefly; the same
     * value is snapshotted as a period's rollup when it closes.
     */
    public function rawStorage(Space $space): float
    {
        return (float) Cache::remember(
            "space.usage.storage.{$space->id}",
            self::STORAGE_CACHE_TTL,
            fn () => (int) (Asset::sum('size') ?? 0),
        );
    }

    /** Total egress traffic (bytes) within the window. */
    public function rawTraffic(Space $space, Carbon $start, Carbon $end): float
    {
        return (float) SpaceTrafficUsageHourly::where('space_id', $space->id)
            ->whereBetween('hour_timestamp', [$start, $end])
            ->sum('total_bytes');
    }

    /** Total API requests within the window. */
    public function rawRequests(Space $space, Carbon $start, Carbon $end): float
    {
        return (float) SpaceApiHitHourly::where('space_id', $space->id)
            ->whereBetween('hour_timestamp', [$start, $end])
            ->sum('hit_count');
    }

    private function storage(Space $space, ?float $limit): UsageMetricDto
    {
        return new UsageMetricDto('storage', 'bytes', $this->rawStorage($space), $limit);
    }

    private function traffic(Space $space, Carbon $start, Carbon $end, ?float $limit): UsageMetricDto
    {
        return new UsageMetricDto('traffic', 'bytes', $this->rawTraffic($space, $start, $end), $limit);
    }

    private function requests(Space $space, Carbon $start, Carbon $end, ?float $limit): UsageMetricDto
    {
        return new UsageMetricDto('requests', 'count', $this->rawRequests($space, $start, $end), $limit);
    }

    private function ai(Space $space, ?float $limit): UsageMetricDto
    {
        $ai = $this->aiUsage->forSpace($space);

        // The plan's aiCredit is the authoritative limit (consistent with the
        // other dimensions); fall back to the live key limit if the plan has none.
        $effectiveLimit = $limit ?? ($ai->unlimited ? null : $ai->limit);

        return new UsageMetricDto(
            key: 'ai',
            unit: 'usd',
            used: $ai->available ? $ai->used : 0.0,
            limit: $effectiveLimit,
            available: $ai->available,
        );
    }
}
