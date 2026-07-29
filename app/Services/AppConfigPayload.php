<?php

namespace App\Services;

use App\Models\User\UserSocialLink;
use App\Support\EditionGate;

/**
 * The window.__APP_CONFIG__ payload injected into the SPA shell. Keep the
 * shape in sync with resources/js/lib/runtime-config.ts.
 */
class AppConfigPayload
{
    public function toArray(): array
    {
        return [
            'version' => config('app.version'),
            'docsUrl' => config('app.docs_url'),
            'communityUrl' => config('app.community_url'),
            'sidebarMenu' => config('app.sidebar_menu', []),
            'apiBaseUrl' => config('app.api_url', ''),
            'features' => EditionGate::features(),
            'socialAuth' => [
                'providers' => collect(UserSocialLink::SOCIAL_SERVICES)
                    ->filter(fn (string $provider) => filled(config("services.{$provider}.client_id"))
                        && filled(config("services.{$provider}.client_secret")))
                    ->map(fn (string $provider) => [
                        'key' => $provider,
                        'url' => route('auth.social.redirect', ['provider' => $provider]),
                        'linkUrl' => route('auth.social.link.redirect', ['provider' => $provider]),
                    ])
                    ->values(),
            ],
            'posthog' => [
                'key' => config('services.posthog.api_key'),
                'host' => config('services.posthog.settings.host'),
            ],
            'echo' => $this->echo(),
            'ilum' => [
                'baseURL' => $this->ilumBaseUrl(),
            ],
        ];
    }

    /**
     * Null when realtime is not configured, so the SPA skips Echo entirely
     * instead of retry-looping against a websocket that isn't there.
     */
    private function echo(): ?array
    {
        if (! EditionGate::realtimeEnabled()) {
            return null;
        }

        return [
            'broadcaster' => 'reverb',
            'key' => config('reverb.apps.apps.0.key'),
            'wsHost' => config('reverb.apps.apps.0.options.host'),
            'wsPort' => (string) config('reverb.apps.apps.0.options.port'),
            'wssPort' => (string) config('reverb.apps.apps.0.options.port'),
            'forceTLS' => (bool) config('reverb.apps.apps.0.options.useTLS', true),
            'enabledTransports' => ['ws', 'wss'],
        ];
    }

    private function ilumBaseUrl(): string
    {
        if ($base = config('ilum.base_url')) {
            return $base;
        }

        return EditionGate::isSaas()
            ? 'https://api.b10cks.com/ilum'
            : rtrim((string) config('app.url'), '/').'/ilum';
    }
}
