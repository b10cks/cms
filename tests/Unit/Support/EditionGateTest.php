<?php

namespace Tests\Unit\Support;

use App\Enums\Edition;
use App\Support\EditionGate;
use Tests\TestCase;

class EditionGateTest extends TestCase
{
    public function test_defaults_to_saas(): void
    {
        config(['edition.edition' => null]);

        $this->assertSame(Edition::SAAS, EditionGate::edition());
        $this->assertTrue(EditionGate::isSaas());
        $this->assertTrue(EditionGate::billingEnabled());
    }

    public function test_invalid_edition_falls_back_to_saas(): void
    {
        config(['edition.edition' => 'enterprise']);

        $this->assertSame(Edition::SAAS, EditionGate::edition());
    }

    public function test_self_hosted_disables_billing(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $this->assertTrue(EditionGate::isSelfHosted());
        $this->assertFalse(EditionGate::billingEnabled());
    }

    public function test_billing_override_wins_over_edition(): void
    {
        config(['edition.edition' => 'self-hosted', 'edition.features.billing' => true]);
        $this->assertTrue(EditionGate::billingEnabled());

        config(['edition.edition' => 'saas', 'edition.features.billing' => 'false']);
        $this->assertFalse(EditionGate::billingEnabled());
    }

    public function test_ai_metering_follows_ai_mode(): void
    {
        config(['ai.mode' => 'space', 'edition.features.ai' => null]);
        $this->assertTrue(EditionGate::aiMetered());

        config(['ai.mode' => 'single']);
        $this->assertFalse(EditionGate::aiMetered());

        config(['edition.features.ai' => true]);
        $this->assertTrue(EditionGate::aiMetered());
    }

    public function test_realtime_requires_reverb_key_and_broadcaster(): void
    {
        config(['reverb.apps.apps.0.key' => null, 'broadcasting.default' => 'reverb']);
        $this->assertFalse(EditionGate::realtimeEnabled());

        config(['reverb.apps.apps.0.key' => 'app-key', 'broadcasting.default' => 'null']);
        $this->assertFalse(EditionGate::realtimeEnabled());

        config(['broadcasting.default' => 'reverb']);
        $this->assertTrue(EditionGate::realtimeEnabled());
    }

    public function test_features_shape(): void
    {
        config(['edition.edition' => 'self-hosted', 'ai.mode' => 'single']);

        $this->assertSame(
            ['billing' => false, 'ai' => false, 'realtime' => EditionGate::realtimeEnabled()],
            EditionGate::features()
        );
    }
}
