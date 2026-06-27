<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_of_parent_can_create_child_team_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();
        $parent = Team::factory()->create();
        $this->assignTeamRole($parent, $user, 'owner');

        $response = $this->actingAs($user)->postJson(route('mgmt.teams.store'), [
            'name' => 'Child team',
            'parent_id' => $parent->id,
        ]);

        $response->assertSuccessful();
        $childId = $response->json('data.id');

        $this->assertNotNull($childId);
        $this->assertSame($parent->id, $response->json('data.parent_id'));
        $this->assertTeamRole(Team::findOrFail($childId), $user, 'owner');
    }

    #[Test]
    public function creating_a_child_under_an_unowned_parent_is_forbidden(): void
    {
        $user = User::factory()->create();
        $parent = Team::factory()->create();
        // Plain members do not hold team.children.manage.
        $this->assignTeamRole($parent, $user, 'member');

        $response = $this->actingAs($user)->postJson(route('mgmt.teams.store'), [
            'name' => 'Sneaky child',
            'parent_id' => $parent->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('teams', ['name' => 'Sneaky child']);
    }

    #[Test]
    public function non_root_cannot_create_a_top_level_team(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('mgmt.teams.store'), [
            'name' => 'Top level',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('teams', ['name' => 'Top level']);
    }

    #[Test]
    public function root_can_create_a_top_level_team(): void
    {
        $root = User::factory()->create(['is_root' => true]);

        $response = $this->actingAs($root)->postJson(route('mgmt.teams.store'), [
            'name' => 'Top level',
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('teams', ['name' => 'Top level', 'parent_id' => null]);
    }

    #[Test]
    public function reparenting_to_an_unowned_destination_is_forbidden(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $destination = Team::factory()->create();
        $this->assignTeamRole($team, $user, 'owner');

        $response = $this->actingAs($user)->patchJson(route('mgmt.teams.update', $team), [
            'parent_id' => $destination->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(null, $team->fresh()->parent_id);
    }

    #[Test]
    public function reparenting_to_an_owned_destination_is_allowed(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $destination = Team::factory()->create();
        $this->assignTeamRole($team, $user, 'owner');
        $this->assignTeamRole($destination, $user, 'owner');

        $response = $this->actingAs($user)->patchJson(route('mgmt.teams.update', $team), [
            'parent_id' => $destination->id,
        ]);

        $response->assertSuccessful();
        $this->assertSame($destination->id, $team->fresh()->parent_id);
    }

    #[Test]
    public function non_root_cannot_move_a_team_to_the_top_level(): void
    {
        $user = User::factory()->create();
        $parent = Team::factory()->create();
        $team = Team::factory()->create(['parent_id' => $parent->id]);
        $this->assignTeamRole($team, $user, 'owner');

        $response = $this->actingAs($user)->patchJson(route('mgmt.teams.update', $team), [
            'parent_id' => null,
        ]);

        $response->assertForbidden();
        $this->assertSame($parent->id, $team->fresh()->parent_id);
    }

    #[Test]
    public function root_can_move_a_team_to_the_top_level(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $parent = Team::factory()->create();
        $team = Team::factory()->create(['parent_id' => $parent->id]);

        $response = $this->actingAs($root)->patchJson(route('mgmt.teams.update', $team), [
            'parent_id' => null,
        ]);

        $response->assertSuccessful();
        $this->assertNull($team->fresh()->parent_id);
    }

    #[Test]
    public function a_team_cannot_be_its_own_parent(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $team = Team::factory()->create();

        $response = $this->actingAs($root)->patchJson(route('mgmt.teams.update', $team), [
            'parent_id' => $team->id,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function capability_flags_reflect_the_users_team_role(): void
    {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $member, 'member');
        $this->assignTeamRole($team, $owner, 'owner');

        $memberFlags = $this->actingAs($member)
            ->getJson(route('mgmt.teams.show', $team))
            ->json('data');

        $this->assertTrue($memberFlags['can_view_detail']);
        $this->assertFalse($memberFlags['can_update']);
        $this->assertFalse($memberFlags['can_delete']);
        $this->assertFalse($memberFlags['can_create_child']);

        $ownerFlags = $this->actingAs($owner)
            ->getJson(route('mgmt.teams.show', $team))
            ->json('data');

        $this->assertTrue($ownerFlags['can_update']);
        $this->assertTrue($ownerFlags['can_delete']);
        $this->assertTrue($ownerFlags['can_manage_members']);
        $this->assertTrue($ownerFlags['can_create_child']);
    }

    #[Test]
    public function an_owner_can_delete_a_team_they_just_created(): void
    {
        $user = User::factory()->create();
        $parent = Team::factory()->create();
        $this->assignTeamRole($parent, $user, 'owner');

        $childId = $this->actingAs($user)->postJson(route('mgmt.teams.store'), [
            'name' => 'Disposable',
            'parent_id' => $parent->id,
        ])->json('data.id');

        $response = $this->actingAs($user)->deleteJson(route('mgmt.teams.destroy', $childId));

        $response->assertNoContent();
        $this->assertSoftDeleted('teams', ['id' => $childId]);
    }

    #[Test]
    public function a_second_owner_of_the_parent_sees_a_new_child_immediately(): void
    {
        $creator = User::factory()->create();
        $observer = User::factory()->create();
        $parent = Team::factory()->create();
        $this->assignTeamRole($parent, $creator, 'owner');
        $this->assignTeamRole($parent, $observer, 'owner');

        $authorization = app(AuthorizationService::class);
        // Prime the observer's cached graph before the change.
        $this->assertContains($parent->id, $authorization->accessibleTeamIds($observer));

        $childId = $this->actingAs($creator)->postJson(route('mgmt.teams.store'), [
            'name' => 'Fresh child',
            'parent_id' => $parent->id,
        ])->json('data.id');

        $this->assertContains($childId, $authorization->accessibleTeamIds($observer));
    }

    #[Test]
    public function reparenting_updates_inherited_access_for_both_ancestor_chains(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $source = Team::factory()->create();
        $destination = Team::factory()->create();
        $moved = Team::factory()->create(['parent_id' => $source->id]);

        $sourceOwner = User::factory()->create();
        $destinationOwner = User::factory()->create();
        $this->assignTeamRole($source, $sourceOwner, 'owner');
        $this->assignTeamRole($destination, $destinationOwner, 'owner');

        $authorization = app(AuthorizationService::class);
        // Prime both graphs: source owner inherits the moved team, destination owner does not.
        $this->assertContains($moved->id, $authorization->accessibleTeamIds($sourceOwner));
        $this->assertNotContains($moved->id, $authorization->accessibleTeamIds($destinationOwner));

        $this->actingAs($root)->patchJson(route('mgmt.teams.update', $moved), [
            'parent_id' => $destination->id,
        ])->assertSuccessful();

        $this->assertNotContains($moved->id, $authorization->accessibleTeamIds($sourceOwner));
        $this->assertContains($moved->id, $authorization->accessibleTeamIds($destinationOwner));
    }
}
