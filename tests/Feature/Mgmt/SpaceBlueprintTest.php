<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\SpaceBlueprintController;
use App\Http\Controllers\Mgmt\SystemSpaceBlueprintController;
use App\Models\Management\Space;
use App\Models\Management\SpaceBlueprint;
use App\Models\Management\Team;
use App\Models\Space\Block;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(SpaceBlueprintController::class)]
#[CoversClass(SystemSpaceBlueprintController::class)]
class SpaceBlueprintTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private function blueprint(?Team $team, string $name = 'Blueprint'): SpaceBlueprint
    {
        return SpaceBlueprint::create([
            'name' => $name,
            'team_id' => $team?->id,
        ]);
    }

    #[Test]
    public function owner_of_a_parent_team_can_create_a_blueprint_for_a_child_team(): void
    {
        $user = User::factory()->create();
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);

        $this->assignTeamRole($parent, $user, 'owner');
        $this->actingAs($user);

        $response = $this->postJson(route('mgmt.blueprints.store', $child), [
            'name' => 'Child Blueprint',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.team_id', $child->id);
    }

    #[Test]
    public function a_team_member_cannot_create_a_blueprint(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $this->assignTeamRole($team, $user, 'member');
        $this->actingAs($user);

        $this->postJson(route('mgmt.blueprints.store', $team), ['name' => 'Nope'])
            ->assertForbidden();
    }

    #[Test]
    public function a_source_space_may_live_in_another_team_the_user_can_read(): void
    {
        $user = User::factory()->create();
        $blueprintTeam = Team::factory()->create();
        $sourceTeam = Team::factory()->create();
        $sourceSpace = Space::factory()->withLive()->create([
            'team_id' => $sourceTeam->id,
            'settings' => ['default_language' => 'de'],
        ]);

        $this->assignTeamRole($blueprintTeam, $user, 'admin');
        $this->assignTeamRole($sourceTeam, $user, 'owner');
        $this->actingAs($user);

        $response = $this->postJson(route('mgmt.blueprints.store', $blueprintTeam), [
            'name' => 'Cross Team',
            'source_space_id' => $sourceSpace->id,
            'tables' => [],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.team_id', $blueprintTeam->id)
            ->assertJsonPath('data.settings.default_language', 'de');
    }

    #[Test]
    public function a_source_space_the_user_cannot_read_is_rejected(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $sourceSpace = Space::factory()->withLive()->create(['team_id' => Team::factory()->create()->id]);

        $this->assignTeamRole($team, $user, 'owner');
        $this->actingAs($user);

        $this->postJson(route('mgmt.blueprints.store', $team), [
            'name' => 'Stolen',
            'source_space_id' => $sourceSpace->id,
        ])->assertForbidden();
    }

    #[Test]
    public function a_source_space_that_never_finished_provisioning_is_rejected(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $sourceSpace = Space::factory()->create(['team_id' => $team->id, 'state' => 'draft']);

        $this->assignTeamRole($team, $user, 'owner');
        $this->actingAs($user);

        $this->postJson(route('mgmt.blueprints.store', $team), [
            'name' => 'Too early',
            'source_space_id' => $sourceSpace->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function settings_that_describe_one_space_are_not_copied_into_a_blueprint(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $sourceSpace = Space::factory()->withLive()->create([
            'team_id' => $team->id,
            'settings' => [
                'default_language' => 'en',
                'environments' => [['name' => 'Preview', 'url' => 'https://preview.example.com']],
                'default_environment' => 'Preview',
                'onboarding_dismissed_at' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $this->assignTeamRole($team, $user, 'owner');
        $this->actingAs($user);

        $response = $this->postJson(route('mgmt.blueprints.store', $team), [
            'name' => 'Portable',
            'source_space_id' => $sourceSpace->id,
        ]);

        $response->assertStatus(201);

        $settings = $response->json('data.settings');
        $this->assertSame('en', $settings['default_language']);
        $this->assertArrayNotHasKey('environments', $settings);
        $this->assertArrayNotHasKey('default_environment', $settings);
        $this->assertArrayNotHasKey('onboarding_dismissed_at', $settings);
    }

    #[Test]
    public function the_selected_tables_are_snapshotted_from_the_source_space(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $sourceSpace = Space::factory()->withLive()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $user, 'owner');
        $this->setUpSpaceTesting($sourceSpace);

        Block::create(['slug' => 'hero', 'name' => 'Hero', 'type' => 'content_type', 'schema' => []]);

        $this->actingAs($user);

        $response = $this->postJson(route('mgmt.blueprints.store', $team), [
            'name' => 'Snapshot',
            'source_space_id' => $sourceSpace->id,
            'tables' => ['blocks', 'block_tags'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.snapshot.blocks.0.slug', 'hero')
            ->assertJsonPath('data.snapshot.block_tags', []);

        $this->assertArrayNotHasKey('data_sources', $response->json('data.snapshot'));
    }

    #[Test]
    public function only_root_may_create_a_blueprint_without_a_team(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner)
            ->postJson(route('mgmt.space-blueprints.store'), ['name' => 'System'])
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['is_root' => true]))
            ->postJson(route('mgmt.space-blueprints.store'), ['name' => 'System'])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', null);
    }

    #[Test]
    public function only_root_may_change_a_blueprint_without_a_team(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');
        $blueprint = $this->blueprint(null, 'System');

        $this->actingAs($owner)
            ->patchJson(route('mgmt.space-blueprints.update', $blueprint), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->deleteJson(route('mgmt.space-blueprints.destroy', $blueprint))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['is_root' => true]))
            ->patchJson(route('mgmt.space-blueprints.update', $blueprint), ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    #[Test]
    public function the_system_endpoints_only_address_blueprints_without_a_team(): void
    {
        $team = Team::factory()->create();
        $blueprint = $this->blueprint($team);

        $this->actingAs(User::factory()->create(['is_root' => true]))
            ->getJson(route('mgmt.space-blueprints.show', $blueprint))
            ->assertNotFound();
    }

    #[Test]
    public function everyone_may_read_a_blueprint_without_a_team(): void
    {
        $blueprint = $this->blueprint(null, 'System');

        $this->actingAs(User::factory()->create())
            ->getJson(route('mgmt.space-blueprints.show', $blueprint))
            ->assertOk()
            ->assertJsonPath('data.id', $blueprint->id);
    }

    #[Test]
    public function the_available_list_covers_system_and_inherited_team_blueprints(): void
    {
        $user = User::factory()->create();
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);
        $foreign = Team::factory()->create();

        $this->assignTeamRole($parent, $user, 'member');

        $system = $this->blueprint(null, 'System');
        $childBlueprint = $this->blueprint($child, 'Child');
        $this->blueprint($foreign, 'Foreign');

        $this->actingAs($user);

        $ids = collect($this->getJson(route('mgmt.space-blueprints'))->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([$system->id, $childBlueprint->id], $ids);
    }

    #[Test]
    public function the_team_list_is_scoped_to_that_team(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $other = Team::factory()->create();

        $this->assignTeamRole($team, $user, 'member');
        $this->assignTeamRole($other, $user, 'member');

        $own = $this->blueprint($team, 'Own');
        $this->blueprint($other, 'Other');
        $this->blueprint(null, 'System');

        $this->actingAs($user);

        $ids = collect($this->getJson(route('mgmt.blueprints.index', $team))->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$own->id], $ids);
    }

    #[Test]
    public function a_blueprint_from_a_foreign_team_cannot_seed_a_new_space(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $foreign = $this->blueprint(Team::factory()->create(), 'Foreign');

        $this->actingAs($user)
            ->postJson(route('mgmt.spaces.store'), [
                'name' => 'Seeded',
                'slug' => 'seeded',
                'blueprint_id' => $foreign->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_blueprint_without_a_team_can_seed_a_new_space(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $system = $this->blueprint(null, 'System');

        $this->actingAs($user)
            ->postJson(route('mgmt.spaces.store'), [
                'name' => 'Seeded',
                'slug' => 'seeded',
                'blueprint_id' => $system->id,
            ])
            ->assertStatus(201);
    }
}
