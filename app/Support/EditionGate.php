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
     * Whether open self-registration (no invite) is accepted. SaaS is always
     * open; a self-hosted instance is only open until the first account
     * exists — the installer's "create the first account, it becomes the
     * owner" step — and invite-only afterwards, so a fresh install on a
     * public address cannot be claimed by a stranger later. Override with
     * B10CKS_ALLOW_REGISTRATION.
     */
    public static function registrationOpen(): bool
    {
        $override = self::override('registration');
        if ($override !== null) {
            return $override;
        }

        if (self::isSaas()) {
            return true;
        }

        // Before setup has migrated the database the check cannot run; stay
        // open so the first boot is not bricked.
        return rescue(fn () => ! \App\Models\User::query()->exists(), true, false);
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
     * @return array{billing: bool, ai: bool, realtime: bool, registration: bool}
     */
    public static function features(): array
    {
        return [
            'billing' => self::billingEnabled(),
            'ai' => self::aiMetered(),
            'realtime' => self::realtimeEnabled(),
            'registration' => self::registrationOpen(),
        ];
    }

    private static function override(string $feature): ?bool
    {
        $value = config("edition.features.{$feature}");

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
