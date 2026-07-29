<?php

namespace Tests\Feature\Web;

use App\Services\AppConfigPayload;
use Tests\TestCase;

class AppConfigPayloadTest extends TestCase
{
    public function test_echo_is_null_without_reverb_key(): void
    {
        config(['reverb.apps.apps.0.key' => null, 'broadcasting.default' => 'reverb']);

        $this->assertNull(app(AppConfigPayload::class)->toArray()['echo']);
    }

    public function test_echo_is_null_with_null_broadcaster(): void
    {
        config(['reverb.apps.apps.0.key' => 'app-key', 'broadcasting.default' => 'null']);

        $this->assertNull(app(AppConfigPayload::class)->toArray()['echo']);
    }

    public function test_echo_is_populated_when_reverb_configured(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'reverb.apps.apps.0.key' => 'app-key',
            'reverb.apps.apps.0.options.host' => 'ws.example.com',
            'reverb.apps.apps.0.options.port' => 443,
            'reverb.apps.apps.0.options.useTLS' => true,
        ]);

        $this->assertSame([
            'broadcaster' => 'reverb',
            'key' => 'app-key',
            'wsHost' => 'ws.example.com',
            'wsPort' => '443',
            'wssPort' => '443',
            'forceTLS' => true,
            'enabledTransports' => ['ws', 'wss'],
        ], app(AppConfigPayload::class)->toArray()['echo']);
    }

    public function test_features_reflect_edition(): void
    {
        config(['edition.edition' => 'self-hosted', 'ai.mode' => 'single']);

        $features = app(AppConfigPayload::class)->toArray()['features'];

        $this->assertFalse($features['billing']);
        $this->assertFalse($features['ai']);
    }

    public function test_ilum_base_url_falls_back_per_edition(): void
    {
        config(['ilum.base_url' => null, 'edition.edition' => 'saas']);
        $this->assertSame('https://api.b10cks.com/ilum', app(AppConfigPayload::class)->toArray()['ilum']['baseURL']);

        config(['edition.edition' => 'self-hosted', 'app.url' => 'https://cms.example.com/']);
        $this->assertSame('https://cms.example.com/ilum', app(AppConfigPayload::class)->toArray()['ilum']['baseURL']);

        config(['ilum.base_url' => 'https://cdn.example.com/ilum']);
        $this->assertSame('https://cdn.example.com/ilum', app(AppConfigPayload::class)->toArray()['ilum']['baseURL']);
    }

    public function test_app_shell_injects_payload(): void
    {
        // CI runs the suite without a frontend build, so the real Vite
        // manifest does not exist there.
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('window.__APP_CONFIG__', false);
        $response->assertSee('features', false);
    }
}
