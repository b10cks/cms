<?php

namespace App\Services\Ai;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiKey;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles a space's OpenRouter key with the entitlement derived from its
 * subscription/plan. Idempotent: provisions a missing key, updates the spend
 * limit when the plan changes, reissues at the start of each reset period to
 * refresh the budget, and revokes keys once a space is no longer eligible.
 */
class SpaceAiKeyProvisioner
{
    public function __construct(
        private readonly PlanAiKeyResolver $resolver,
        private readonly OpenRouterKeyManager $keys,
    ) {
    }

    public function syncForSpace(Space $space, bool $forceReissue = false): ?SpaceAiKey
    {
        if (! config('ai.drivers.openrouter.enabled', false)) {
            return null;
        }

        if (empty(config('ai.drivers.openrouter.management_key'))) {
            Log::warning('OpenRouter management key not configured; cannot sync space AI key', [
                'space' => $space->id,
            ]);

            return null;
        }

        $spec = $this->resolver->resolve($space);

        $activeKeys = $space->aiKeys()
            ->forDriver('openrouter')
            ->active()
            ->latest()
            ->get();

        if (! $spec->eligible) {
            // Subscription lapsed or downgraded to a plan without AI — revoke.
            $activeKeys->each(fn (SpaceAiKey $key) => $this->safeRevoke($key));

            return null;
        }

        $limit = $spec->effectiveLimit();

        if ($activeKeys->isEmpty()) {
            return $this->provision($space, $spec);
        }

        // Keep the newest key; revoke any stray duplicates.
        $current = $activeKeys->shift();
        $activeKeys->each(fn (SpaceAiKey $key) => $this->safeRevoke($key));

        if ($forceReissue || $this->isDueForReissue($current)) {
            $this->safeRevoke($current);

            return $this->provision($space, $spec);
        }

        // Reconcile the spend limit when the plan changed (upgrade/downgrade).
        if ($this->limitChanged($current, $limit)) {
            try {
                $this->keys->updateKeyLimit($current, $limit, $limit !== null ? $spec->limitReset : null);
            } catch (\Throwable $e) {
                Log::warning('Failed to update OpenRouter key limit', [
                    'space' => $space->id,
                    'key_id' => $current->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $current;
    }

    private function provision(Space $space, AiKeySpec $spec): ?SpaceAiKey
    {
        $limit = $spec->effectiveLimit();

        try {
            $key = $this->keys->provisionKey(
                space: $space,
                limit: $limit,
                limitReset: $limit !== null ? $spec->limitReset : null,
                expiresAt: $spec->expiresAt,
            );

            Log::info('Provisioned OpenRouter key for space', [
                'space' => $space->id,
                'key_id' => $key->id,
                'limit' => $limit,
                'unlimited' => $spec->unlimited,
            ]);

            return $key;
        } catch (\Throwable $e) {
            Log::warning('Failed to provision OpenRouter key', [
                'space' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function safeRevoke(SpaceAiKey $key): void
    {
        try {
            $this->keys->revokeKey($key);
        } catch (\Throwable $e) {
            Log::warning('Failed to revoke OpenRouter key, disabling locally', [
                'key_id' => $key->id,
                'error' => $e->getMessage(),
            ]);

            // Ensure the key is no longer used locally even if the remote
            // revoke failed (e.g. already deleted upstream).
            $key->update(['disabled_at' => now()]);
        }
    }

    /**
     * A key is due for reissue once it was issued before the start of the
     * current reset period, so the per-period spend budget refreshes.
     */
    private function isDueForReissue(SpaceAiKey $key): bool
    {
        if ($key->created_at === null) {
            return true;
        }

        return $key->created_at->lt($this->currentPeriodStart());
    }

    private function currentPeriodStart(): \Illuminate\Support\Carbon
    {
        return match (config('ai.drivers.openrouter.key_reset', 'monthly')) {
            'daily' => now()->startOfDay(),
            'weekly' => now()->startOfWeek(),
            'annually', 'yearly' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };
    }

    private function limitChanged(SpaceAiKey $key, ?float $limit): bool
    {
        $existing = $key->limit === null ? null : (float) $key->limit;

        if ($existing === null && $limit === null) {
            return false;
        }

        if ($existing === null || $limit === null) {
            return true;
        }

        return abs($existing - $limit) > 0.001;
    }
}
