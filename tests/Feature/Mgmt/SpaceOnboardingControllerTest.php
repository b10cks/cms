<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpaceOnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Space $space;

    protected string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->user, 'owner');

        $this->baseUrl = "/mgmt/v1/spaces/{$this->space->id}/onboarding";
    }

    #[Test]
    public function owner_can_dismiss_onboarding()
    {
        Sanctum::actingAs($this->user);

        $response = $this->patchJson($this->baseUrl, ['dismissed' => true]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.settings.onboarding_dismissed_at'));
        $this->assertNotNull($this->space->fresh()->settings->toArray()['onboarding_dismissed_at']);
    }

    #[Test]
    public function owner_can_restore_onboarding()
    {
        $this->space->settings = ['onboarding_dismissed_at' => now()->toIso8601String()];
        $this->space->save();

        Sanctum::actingAs($this->user);

        $response = $this->patchJson($this->baseUrl, ['dismissed' => false]);

        $response->assertOk();
        $this->assertNull($response->json('data.settings.onboarding_dismissed_at'));
        $this->assertNull($this->space->fresh()->settings->toArray()['onboarding_dismissed_at']);
    }

    #[Test]
    public function dismissing_preserves_other_settings()
    {
        $this->space->settings = ['default_language' => 'de', 'visual_editor' => false];
        $this->space->save();

        Sanctum::actingAs($this->user);

        $this->patchJson($this->baseUrl, ['dismissed' => true])->assertOk();

        $settings = $this->space->fresh()->settings->toArray();

        $this->assertSame('de', $settings['default_language']);
        $this->assertFalse($settings['visual_editor']);
        $this->assertNotNull($settings['onboarding_dismissed_at']);
    }

    #[Test]
    public function dismissed_is_required()
    {
        Sanctum::actingAs($this->user);

        $this->patchJson($this->baseUrl, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dismissed');
    }

    #[Test]
    public function regular_member_cannot_dismiss_onboarding()
    {
        $member = User::factory()->create();
        $this->assignSpaceRole($this->space, $member, 'member');

        Sanctum::actingAs($member);

        $this->patchJson($this->baseUrl, ['dismissed' => true])->assertForbidden();

        $this->assertNull($this->space->fresh()->settings->toArray()['onboarding_dismissed_at']);
    }

    #[Test]
    public function non_member_cannot_dismiss_onboarding()
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson($this->baseUrl, ['dismissed' => true])->assertForbidden();
    }
}
