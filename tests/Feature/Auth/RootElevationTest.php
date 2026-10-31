<?php

namespace Tests\Feature\Auth;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RootElevationTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private Team $team;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = User::factory()->create(['is_root' => true]);
        $this->team = Team::factory()->create();
        $this->space = Space::factory()->create(['team_id' => $this->team->id]);
    }

    #[Test]
    public function a_root_user_without_elevation_reads_everything_but_writes_nothing(): void
    {
        $this->actingAs($this->root);

        $this->getJson(route('mgmt.teams.show', $this->team))->assertOk();
        $this->getJson(route('mgmt.spaces.show', $this->space))->assertOk();

        $this->patchJson(route('mgmt.teams.update', $this->team), ['name' => 'Renamed'])->assertForbidden();
        $this->putJson(route('mgmt.spaces.update', $this->space), ['name' => 'Renamed'])->assertForbidden();
        $this->postJson(route('mgmt.teams.store'), ['name' => 'Top level'])->assertForbidden();
        $this->postJson(route('mgmt.teams.users.store', $this->team), [
            'user_id' => User::factory()->create()->id,
            'role' => 'member',
        ])->assertForbidden();

        $payload = $this->getJson(route('mgmt.authorization.show', ['space_id' => $this->space->id]))
            ->assertOk()
            ->json('data');

        $this->assertTrue($payload['is_root']);
        $this->assertFalse($payload['is_elevated']);
        $this->assertContains('space.view', $payload['space']['abilities']);
        $this->assertContains('content.view', $payload['space']['abilities']);
        $this->assertNotContains('space.update', $payload['space']['abilities']);
        $this->assertNotContains('content.manage', $payload['space']['abilities']);
        $this->assertNotContains('comments.react', $payload['space']['abilities']);
    }

    #[Test]
    public function a_root_user_keeps_their_own_membership_rights_without_elevation(): void
    {
        $this->assignTeamRole($this->team, $this->root, 'owner');

        $this->actingAs($this->root)
            ->patchJson(route('mgmt.teams.update', $this->team), ['name' => 'Renamed by owner'])
            ->assertOk();
    }

    #[Test]
    public function elevation_unlocks_writes_until_it_expires(): void
    {
        $this->actingAs($this->root);

        $this->postJson(route('mgmt.root.elevation.store'), ['reason' => 'Ticket #4821'])
            ->assertCreated()
            ->assertJsonPath('data.is_elevated', true)
            ->assertJsonPath('data.elevation_reason', 'Ticket #4821');

        $this->assertDatabaseHas('audit_logs', ['action' => 'root.elevation.started', 'user_id' => $this->root->id]);

        $this->patchJson(route('mgmt.teams.update', $this->team), ['name' => 'Renamed'])->assertOk();
        $this->assertContains(
            'space.update',
            $this->getJson(route('mgmt.authorization.show', ['space_id' => $this->space->id]))->json('data.space.abilities'),
        );

        $this->travel(31)->minutes();

        $this->patchJson(route('mgmt.teams.update', $this->team), ['name' => 'Too late'])->assertForbidden();
        $this->getJson(route('mgmt.root.elevation.show'))->assertJsonPath('data.is_elevated', false);
    }

    #[Test]
    public function ending_an_elevation_takes_effect_on_the_next_request(): void
    {
        $this->actingAs($this->root);
        $this->postJson(route('mgmt.root.elevation.store'), ['reason' => 'Restore owner', 'duration' => 15])->assertCreated();

        $this->deleteJson(route('mgmt.root.elevation.destroy'))->assertOk()->assertJsonPath('data.is_elevated', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'root.elevation.ended', 'user_id' => $this->root->id]);
        $this->patchJson(route('mgmt.teams.update', $this->team), ['name' => 'Renamed'])->assertForbidden();
    }

    #[Test]
    public function elevation_needs_root_a_reason_and_a_known_duration(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('mgmt.root.elevation.store'), ['reason' => 'Let me in'])
            ->assertForbidden();

        $this->actingAs($this->root);
        $this->postJson(route('mgmt.root.elevation.store'), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('mgmt.root.elevation.store'), ['reason' => 'Long one', 'duration' => 240])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration');
    }
}
