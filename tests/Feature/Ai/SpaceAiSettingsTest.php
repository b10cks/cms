<?php

namespace Tests\Feature\Ai;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class SpaceAiSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        $this->actingAs($this->user);
    }

    #[Test]
    public function a_partly_saved_ai_section_still_reads_as_enabled(): void
    {
        $settings = $this->space->settings;
        $settings->ai = ['asset_classification' => ['auto_on_upload' => false]];
        $this->space->settings = $settings;
        $this->space->save();

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/ai-settings")
            ->assertOk()
            ->assertJsonPath('data.ai.enabled', true)
            ->assertJsonPath('data.ai.asset_classification.auto_on_upload', false);
    }

    #[Test]
    public function an_explicit_off_stays_off(): void
    {
        $settings = $this->space->settings;
        $settings->ai = ['enabled' => false];
        $this->space->settings = $settings;
        $this->space->save();

        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/ai-settings")
            ->assertOk()
            ->assertJsonPath('data.ai.enabled', false);
    }

    #[Test]
    public function switching_ai_off_is_stored(): void
    {
        $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/ai-settings", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.ai.enabled', false);

        $this->assertFalse($this->space->fresh()->settings->ai['enabled']);
    }

    #[Test]
    public function the_space_update_saves_classification_options_but_not_the_ai_switch(): void
    {
        $this->patchJson("/mgmt/v1/spaces/{$this->space->id}", [
            'settings' => [
                'ai' => [
                    'enabled' => false,
                    'asset_classification' => ['auto_on_upload' => true, 'suggest_tags' => true, 'allowed_fields' => ['alt']],
                ],
            ],
        ])->assertOk();

        $ai = $this->space->fresh()->settings->ai;
        $this->assertArrayNotHasKey('enabled', $ai);
        $this->assertTrue($ai['asset_classification']['auto_on_upload']);
        $this->assertSame(['alt'], $ai['asset_classification']['allowed_fields']);
    }
}
