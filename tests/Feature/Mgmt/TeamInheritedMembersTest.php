<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Team roles cascade downward, so a member of an ancestor team already holds
 * that role in every team below it. These cover the other half: surfacing those
 * members in the child team's people list without making them editable there.
 */
class TeamInheritedMembersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_parent_team_member_is_listed_in_the_child_team(): void
    {
        $parent = Team::factory()->create(['name' => 'Agency']);
        $child = Team::factory()->create(['parent_id' => $parent->id]);
        $inherited = User::factory()->create();
        $this->assignTeamRole($parent, $inherited, 'admin');

        $root = User::factory()->create(['is_root' => true]);
        $people = $this->actingAs($root)
            ->getJson(route('mgmt.teams.people.index', $child))
            ->assertSuccessful()
            ->json('data');

        $entry = collect($people)->firstWhere('id', $inherited->id);

        $this->assertNotNull($entry);
        $this->assertSame('inherited', $entry['membership_origin']);
        $this->assertSame('admin', $entry['role']);
        $this->assertSame(['id' => $parent->id, 'name' => 'Agency'], $entry['inherited_from']);
        $this->assertFalse($entry['can_assign_role']);
        $this->assertFalse($entry['can_remove']);
    }

    #[Test]
    public function the_strongest_role_up_the_chain_wins(): void
    {
        $grandparent = Team::factory()->create(['name' => 'Holding']);
        $parent = Team::factory()->create(['parent_id' => $grandparent->id]);
        $child = Team::factory()->create(['parent_id' => $parent->id]);

        $user = User::factory()->create();
        $this->assignTeamRole($grandparent, $user, 'owner');
        $this->assignTeamRole($parent, $user, 'member');

        $root = User::factory()->create(['is_root' => true]);
        $people = $this->actingAs($root)
            ->getJson(route('mgmt.teams.people.index', $child))
            ->json('data');

        $entries = collect($people)->where('id', $user->id)->values();

        $this->assertCount(1, $entries);
        $this->assertSame('owner', $entries[0]['role']);
        $this->assertSame($grandparent->id, $entries[0]['inherited_from']['id']);
    }

    #[Test]
    public function a_direct_membership_takes_precedence_over_the_inherited_one(): void
    {
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);

        $user = User::factory()->create();
        $this->assignTeamRole($parent, $user, 'member');
        $this->assignTeamRole($child, $user, 'owner');

        $root = User::factory()->create(['is_root' => true]);
        $people = $this->actingAs($root)
            ->getJson(route('mgmt.teams.people.index', $child))
            ->json('data');

        $entries = collect($people)->where('id', $user->id)->values();

        $this->assertCount(1, $entries);
        $this->assertSame('team', $entries[0]['membership_origin']);
        $this->assertSame('owner', $entries[0]['role']);
        $this->assertNull($entries[0]['inherited_from']);
        $this->assertTrue($entries[0]['can_remove']);
    }

    #[Test]
    public function an_inherited_member_cannot_be_removed_from_the_child_team(): void
    {
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);

        $inherited = User::factory()->create();
        $this->assignTeamRole($parent, $inherited, 'member');

        $owner = User::factory()->create();
        $this->assignTeamRole($child, $owner, 'owner');

        $this->actingAs($owner)
            ->deleteJson(route('mgmt.teams.members.destroy', [$child, $inherited]))
            ->assertNotFound();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $parent->id,
            'user_id' => $inherited->id,
        ]);
    }

    #[Test]
    public function an_inherited_role_carries_its_abilities_into_the_child_team(): void
    {
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);

        $owner = User::factory()->create();
        $this->assignTeamRole($parent, $owner, 'owner');

        $this->actingAs($owner)
            ->patchJson(route('mgmt.teams.update', $child), ['name' => 'Renamed from above'])
            ->assertSuccessful();

        $this->assertSame('Renamed from above', $child->fresh()->name);
    }

    #[Test]
    public function a_sibling_team_member_is_not_listed(): void
    {
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);
        $sibling = Team::factory()->create(['parent_id' => $parent->id]);

        $stranger = User::factory()->create();
        $this->assignTeamRole($sibling, $stranger, 'owner');

        $root = User::factory()->create(['is_root' => true]);
        $people = $this->actingAs($root)
            ->getJson(route('mgmt.teams.people.index', $child))
            ->json('data');

        $this->assertEmpty(collect($people)->where('id', $stranger->id));
    }

    #[Test]
    public function an_inherited_role_cannot_be_edited_from_the_child_team(): void
    {
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);

        $inherited = User::factory()->create();
        $this->assignTeamRole($parent, $inherited, 'member');

        $owner = User::factory()->create();
        $this->assignTeamRole($child, $owner, 'owner');

        $this->actingAs($owner)
            ->patchJson(route('mgmt.teams.members.update', [$child, $inherited]), ['role' => 'admin'])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $child->id,
            'user_id' => $inherited->id,
        ]);
    }

    #[Test]
    public function an_inherited_member_can_still_be_added_outright(): void
    {
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);

        $inherited = User::factory()->create();
        $this->assignTeamRole($parent, $inherited, 'member');

        $owner = User::factory()->create();
        $this->assignTeamRole($child, $owner, 'owner');

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.users.store', $child), [
                'user_id' => $inherited->id,
                'role' => 'admin',
            ])
            ->assertSuccessful();

        $this->assertTeamRole($child, $inherited, 'admin');
    }
}
