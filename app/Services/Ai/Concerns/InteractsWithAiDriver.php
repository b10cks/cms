<?php

namespace App\Services\Ai\Concerns;

use App\Models\Management\Space;
use App\Services\Ai\Contracts\AiDriverInterface;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Services\Ai\PlanAiKeyResolver;
use Illuminate\Support\Str;

/**
 * Shared model/driver resolution for the AI stream services. Both the content
 * and content-tree services pick a model from the space's AI config (falling
 * back through space settings and enabled-driver defaults) and resolve the
 * per-space driver instance the same way; this keeps that logic in one place.
 *
 * Requires the using class to expose a `ModelRegistry $registry` property.
 */
trait InteractsWithAiDriver
{
    protected function resolveModelId(Space $space, $aiConfig = null): string
    {
        if ($aiConfig && $aiConfig->driver && $aiConfig->model) {
            return "{$aiConfig->driver}:{$aiConfig->model}";
        }

        $modelId = $space->settings->ai['model'] ?? null;

        if ($modelId && Str::contains($modelId, ':')) {
            return $modelId;
        }

        foreach ($this->registry->getEnabledDrivers() as $driver) {
            $defaultModel = $driver->getDefaultModel();
            if ($defaultModel) {
                return $defaultModel->getFullId();
            }
        }

        return 'openai:gpt-4o-mini';
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseModelId(string $fullId): array
    {
        if (Str::contains($fullId, ':')) {
            return explode(':', $fullId, 2);
        }

        return ['openai', $fullId];
    }

    /**
     * Resolve the driver and model identifier for a space. Throws an
     * {@see AiServiceException} carrying a precise, user-facing reason when the
     * space cannot stream — its plan excludes AI, its per-space key is still
     * provisioning, or the provider is unavailable. Never falls back to the
     * shared platform key.
     *
     * @return array{0: AiDriverInterface, 1: string} [driver, modelIdentifier]
     *
     * @throws AiServiceException
     */
    protected function resolveSpaceDriver(Space $space, $aiConfig): array
    {
        if (! $aiConfig) {
            throw AiServiceException::notConfigured();
        }

        $modelId = $this->resolveModelId($space, $aiConfig);
        [$driverName, $modelIdentifier] = $this->parseModelId($modelId);

        $driver = $this->registry->getDriverForSpace($driverName, $space);

        if ($driver) {
            return [$driver, $modelIdentifier];
        }

        // No driver resolved. A missing base driver means the provider is not
        // configured at all; otherwise (per-space mode) the space simply has no
        // active key — distinguish "plan excludes AI" from "still provisioning".
        if (! $this->registry->getDriver($driverName)) {
            throw AiServiceException::providerUnavailable();
        }

        throw app(PlanAiKeyResolver::class)->resolve($space)->eligible
            ? AiServiceException::notProvisioned()
            : AiServiceException::planExcluded();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildAiOptions($aiConfig, int $defaultMaxTokens, float $defaultTemperature = 0.3): array
    {
        return [
            'max_tokens' => $aiConfig?->max_tokens ?? $defaultMaxTokens,
            // Every current use case asks for strict, schema-shaped JSON, so the
            // fallback temperature is low for reliable structure; a space may
            // still raise it via its AI config when it wants more variation.
            'temperature' => (float) ($aiConfig?->temperature ?? $defaultTemperature),
            // Every current use case asks the model for strict JSON; drivers
            // upgrade this to native JSON mode where the model supports it.
            'json' => true,
        ];
    }
}
