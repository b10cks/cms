<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class SpaceI18nSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function space_settings_accept_valid_fallback_chains_and_normalize_languages(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'default_language' => 'EN',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'en', 'name' => 'English'],
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                    ['code' => 'de-at', 'name' => 'Austrian German', 'fallback_language' => 'de'],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => 'de-at'],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.default_language', 'en');
        $response->assertJsonPath('data.settings.i18n_mode', 'overlay');
        $response->assertJsonPath('data.settings.languages.0.code', 'de');
        $response->assertJsonPath('data.settings.languages.0.fallback_language', null);
        $response->assertJsonPath('data.settings.languages.1.code', 'de-at');
        $response->assertJsonPath('data.settings.languages.1.fallback_language', 'de');
        $response->assertJsonPath('data.settings.languages.2.code', 'fr');
        $response->assertJsonPath('data.settings.languages.2.fallback_language', 'de-at');
        $response->assertJsonMissingPath('data.settings.languages.3');
    }

    #[Test]
    public function space_settings_reject_self_unknown_and_cyclic_fallbacks(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => 'de'],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => 'es'],
                    ['code' => 'it', 'name' => 'Italian', 'fallback_language' => 'pt'],
                    ['code' => 'pt', 'name' => 'Portuguese', 'fallback_language' => 'it'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.languages.0.fallback_language',
            'settings.languages.1.fallback_language',
            'settings.languages.2.fallback_language',
            'settings.languages.3.fallback_language',
        ]);
    }
}
