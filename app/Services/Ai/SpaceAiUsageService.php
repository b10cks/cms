<?php

namespace App\Services\Ai;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiKey;
use App\Services\Ai\Dto\AiUsageDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a space's AI usage against its quota in a provider-agnostic shape.
 *
 * OpenRouter is the primary provider: each space gets its own provisioned key
 * with a USD spend cap, and usage is fetched live from OpenRouter's
 * provisioning API (cached briefly). Other providers expose no per-space
 * metering, so they report as unavailable.
 */
class SpaceAiUsageService
{
    /** How long a live OpenRouter usage snapshot is cached, in seconds. */
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly PlanAiKeyResolver $resolver,
        private readonly OpenRouterKeyManager $keys,
    ) {}

    public function forSpace(Space $space, bool $fresh = false): AiUsageDto
    {
        $provider = $this->resolveProvider($space);

        return match ($provider) {
            'openrouter' => $this->openRouterUsage($space, $fresh),
            default => AiUsageDto::unavailable(
                $provider,
                'Live usage tracking is not available for this provider.'
            ),
        };
    }

    private function resolveProvider(Space $space): string
    {
        return $space->defaultAiConfig?->driver
            ?: config('ai.default', 'openrouter');
    }

    /**
     * Total OpenRouter spend (USD) attributable to a billing period, summed
     * across every key whose lifetime overlapped the window. Prefers the spend
     * captured when a key was revoked ({@see SpaceAiKey::$final_usage_usd});
     * falls back to a live fetch for keys still active. Approximate: OpenRouter
     * only exposes cumulative per-key spend, so a key spanning two periods is
     * attributed in full to each.
     */
    public function spendForWindow(Space $space, Carbon $start, ?Carbon $end = null): float
    {
        $end ??= now();

        $keys = $space->aiKeys()
            ->forDriver('openrouter')
            ->where('created_at', '<=', $end)
            ->where(function ($query) use ($start) {
                $query->whereNull('disabled_at')->orWhere('disabled_at', '>=', $start);
            })
            ->get();

        $total = 0.0;

        foreach ($keys as $key) {
            if ($key->final_usage_usd !== null) {
                $total += (float) $key->final_usage_usd;

                continue;
            }

            try {
                $total += (float) ($this->keys->getKeyUsage($key)['usage'] ?? 0.0);
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch OpenRouter key usage for window rollup', [
                    'space' => $space->id,
                    'key_id' => $key->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $total;
    }

    private function openRouterUsage(Space $space, bool $fresh): AiUsageDto
    {
        $provider = 'openrouter';

        if (! config('ai.drivers.openrouter.enabled', false)) {
            return AiUsageDto::unavailable($provider, 'AI features are not enabled.');
        }

        $spec = $this->resolver->resolve($space);

        if (! $spec->eligible) {
            return AiUsageDto::unavailable(
                $provider,
                'Your current plan does not include AI usage.'
            );
        }

        $reset = $spec->limitReset;

        $key = $space->aiKeys()
            ->forDriver('openrouter')
            ->active()
            ->latest()
            ->first();

        // Eligible, but no key has been provisioned yet (provisioning is async).
        // Report the plan entitlement so the UI can still show the allowance.
        if (! $key) {
            if ($spec->unlimited) {
                return AiUsageDto::unlimited($provider, 'usd', $reset);
            }

            return new AiUsageDto(
                provider: $provider,
                unit: 'usd',
                available: true,
                live: false,
                used: 0.0,
                limit: $spec->limit,
                remaining: $spec->limit,
                reset: $reset,
                resetsAt: $this->nextResetAt($reset),
                message: 'Your AI key is being provisioned.',
            );
        }

        try {
            $data = $this->fetchUsage($key, $fresh);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch OpenRouter key usage', [
                'space' => $space->id,
                'key_id' => $key->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fromLocalKey($provider, $key, $reset);
        }

        $limit = array_key_exists('limit', $data) ? $data['limit'] : $key->limit;
        $limit = $limit === null ? null : (float) $limit;
        $used = (float) ($data['usage'] ?? 0);
        $unlimited = $limit === null;

        $remaining = $data['limit_remaining'] ?? null;
        if ($remaining === null && $limit !== null) {
            $remaining = max(0.0, $limit - $used);
        }

        $cadence = $data['limit_reset'] ?? $reset;

        return new AiUsageDto(
            provider: $provider,
            unit: 'usd',
            available: true,
            unlimited: $unlimited,
            live: true,
            used: $used,
            limit: $limit,
            remaining: $remaining === null ? null : (float) $remaining,
            reset: $cadence,
            resetsAt: $unlimited ? null : $this->nextResetAt($cadence),
            breakdown: [
                'daily' => (float) ($data['usage_daily'] ?? 0),
                'weekly' => (float) ($data['usage_weekly'] ?? 0),
                'monthly' => (float) ($data['usage_monthly'] ?? 0),
            ],
        );
    }

    /**
     * Fallback when the live fetch fails: report what we know locally about the
     * key's spend cap, without a live usage figure.
     */
    private function fromLocalKey(string $provider, SpaceAiKey $key, ?string $reset): AiUsageDto
    {
        $limit = $key->limit === null ? null : (float) $key->limit;
        $cadence = $key->limit_reset ?? $reset;

        if ($limit === null) {
            return AiUsageDto::unlimited($provider, 'usd', $cadence);
        }

        return new AiUsageDto(
            provider: $provider,
            unit: 'usd',
            available: true,
            live: false,
            used: 0.0,
            limit: $limit,
            remaining: $limit,
            reset: $cadence,
            resetsAt: $this->nextResetAt($cadence),
            message: 'Live usage is temporarily unavailable.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUsage(SpaceAiKey $key, bool $fresh): array
    {
        $cacheKey = "ai.usage.openrouter.{$key->id}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            fn () => $this->keys->getKeyUsage($key)
        );
    }

    /**
     * Start of the next reset period for the given cadence (UTC-aligned, as
     * OpenRouter resets spend on UTC boundaries).
     */
    private function nextResetAt(?string $cadence): Carbon
    {
        return match ($cadence) {
            'daily' => now()->addDay()->startOfDay(),
            'weekly' => now()->startOfWeek()->addWeek(),
            'annually', 'yearly' => now()->startOfYear()->addYear(),
            default => now()->startOfMonth()->addMonth(),
        };
    }
}
