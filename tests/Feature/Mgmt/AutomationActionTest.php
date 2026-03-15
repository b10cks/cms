<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Automation;
use App\Models\Management\AutomationAction;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutomationActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected User $owner;

    protected User $editor;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->editor = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->assignSpaceRole($this->space, $this->editor, 'editor');
    }

    #[Test]
    public function owner_can_create_an_automation_action(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.automation-actions.store', $this->space->id), [
            'name' => 'Publish Webhook',
            'description' => 'Notifies downstream systems.',
            'type' => 'webhook',
            'config' => [
                'url' => 'https://example.com/hooks/publish',
                'method' => 'POST',
                'headers' => [
                    'X-Environment' => 'production',
                ],
                'parameters' => [
                    'event' => 'publish',
                ],
                'timeout_seconds' => 10,
            ],
            'secrets' => [
                'signing_key' => 'super-secret',
            ],
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Publish Webhook');
        $response->assertJsonPath('data.type', 'webhook');
        $response->assertJsonPath('data.has_secrets', true);
        $response->assertJsonPath('data.secret_keys.0', 'signing_key');
        $response->assertJsonMissingPath('data.secrets');

        $this->assertDatabaseHas('automation_actions', [
            'space_id' => $this->space->id,
            'name' => 'Publish Webhook',
            'type' => 'webhook',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function editor_cannot_create_an_automation_action(): void
    {
        $this->actingAs($this->editor);

        $response = $this->postJson(route('mgmt.automation-actions.store', $this->space->id), [
            'name' => 'Blocked Action',
            'type' => 'void',
            'config' => [
                'message' => 'This should not be created.',
            ],
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function updating_an_action_can_replace_and_clear_secrets(): void
    {
        $this->actingAs($this->owner);

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'webhook',
            'config' => [
                'url' => 'https://example.com/original',
                'method' => 'POST',
            ],
            'secrets' => [
                'alpha' => 'one',
                'beta' => 'two',
            ],
        ]);

        $response = $this->patchJson(route('mgmt.automation-actions.update', [
            'space' => $this->space->id,
            'automation_action' => $action->id,
        ]), [
            'secrets' => [
                'alpha' => 'three',
            ],
            'clear_secret_keys' => ['beta'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.secret_keys', ['alpha']);

        $this->assertSame(['alpha' => 'three'], $action->fresh()->secrets);
    }

    #[Test]
    public function linked_actions_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner);

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
        ]);

        Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
        ]);

        $response = $this->deleteJson(route('mgmt.automation-actions.destroy', [
            'space' => $this->space->id,
            'automation_action' => $action->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action']);

        $this->assertDatabaseHas('automation_actions', [
            'id' => $action->id,
        ]);
    }
}
