<?php

namespace App\Support;

use App\Enums\Edition;

class EditionGate
{
    public static function edition(): Edition
    {
        return Edition::tryFrom((string) config('edition.edition')) ?? Edition::SAAS;
    }

    public static function isSaas(): bool
    {
        return self::edition() === Edition::SAAS;
    }

    public static function isSelfHosted(): bool
    {
        return self::edition() === Edition::SELF_HOSTED;
    }

    public static function billingEnabled(): bool
    {
        return self::override('billing') ?? self::isSaas();
    }

    /**
     * Whether per-space AI keys are provisioned and metered. In "single" AI
     * mode every space shares the platform key and there is nothing to meter.
     */
    public static function aiMetered(): bool
    {
        return self::override('ai') ?? config('ai.mode') === 'space';
    }

    public static function realtimeEnabled(): bool
    {
        return ! empty(config('reverb.apps.apps.0.key'))
            && ! in_array(config('broadcasting.default'), [null, 'null'], true);
    }

    /**
     * Feature flags exposed to the SPA via the __APP_CONFIG__ payload.
     *
     * @return array{billing: bool, ai: bool, realtime: bool}
     */
    public static function features(): array
    {
        return [
            'billing' => self::billingEnabled(),
            'ai' => self::aiMetered(),
            'realtime' => self::realtimeEnabled(),
        ];
    }

    private static function override(string $feature): ?bool
    {
        $value = config("edition.features.{$feature}");

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
