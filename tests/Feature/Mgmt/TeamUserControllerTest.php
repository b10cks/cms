<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamUserControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function team_user_index_includes_direct_members_and_space_only_members(): void
    {
        $admin = User::factory()->create();
        $directMember = User::factory()->create();
        $spaceOnlyMember = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignTeamRole($team, $directMember, 'member');
        $this->assignSpaceRole($space, $spaceOnlyMember, 'editor');

        $response = $this->actingAs($admin)
            ->getJson(route('mgmt.teams.users.index', $team));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.membership_origin', 'team');

        $spaceOnlyRow = collect($response->json('data'))
            ->firstWhere('id', $spaceOnlyMember->id);

        $this->assertSame('space', $spaceOnlyRow['membership_origin']);
        $this->assertNull($spaceOnlyRow['role']);
        $this->assertTrue($spaceOnlyRow['can_assign_team_role']);
        $this->assertFalse($spaceOnlyRow['can_remove']);
        $this->assertSame($space->id, $spaceOnlyRow['space_memberships'][0]['space']['id']);
        $this->assertSame('editor', $spaceOnlyRow['space_memberships'][0]['role']);
    }

    #[Test]
    public function team_user_index_aggregates_multiple_space_memberships_into_one_row(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create();
        $spaceA = Space::factory()->create([
            'team_id' => $team->id,
            'name' => 'Alpha Space',
        ]);
        $spaceB = Space::factory()->create([
            'team_id' => $team->id,
            'name' => 'Beta Space',
        ]);

        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignSpaceRole($spaceB, $member, 'viewer');
        $this->assignSpaceRole($spaceA, $member, 'editor');

        $response = $this->actingAs($admin)
            ->getJson(route('mgmt.teams.users.index', $team));

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $spaceOnlyRow = collect($response->json('data'))
            ->firstWhere('id', $member->id);

        $this->assertCount(2, $spaceOnlyRow['space_memberships']);
        $this->assertSame('Alpha Space', $spaceOnlyRow['space_memberships'][0]['space']['name']);
        $this->assertSame('Beta Space', $spaceOnlyRow['space_memberships'][1]['space']['name']);
    }

    #[Test]
    public function direct_team_membership_wins_over_space_only_membership_in_team_user_index(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignTeamRole($team, $member, 'member');
        $this->assignSpaceRole($space, $member, 'editor');

        $response = $this->actingAs($admin)
            ->getJson(route('mgmt.teams.users.index', $team));

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $row = collect($response->json('data'))
            ->firstWhere('id', $member->id);

        $this->assertSame('team', $row['membership_origin']);
        $this->assertSame('member', $row['role']);
        $this->assertSame([], $row['space_memberships']);
    }

    #[Test]
    public function team_user_index_role_filter_matches_only_direct_team_roles(): void
    {
        $admin = User::factory()->create();
        $directMember = User::factory()->create();
        $spaceOnlyMember = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignTeamRole($team, $directMember, 'member');
        $this->assignSpaceRole($space, $spaceOnlyMember, 'member');

        $response = $this->actingAs($admin)
            ->getJson(route('mgmt.teams.users.index', [
                'team' => $team,
                'role' => 'eq:member',
            ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $directMember->id);
    }

    #[Test]
    public function patching_a_space_only_team_user_promotes_them_to_a_direct_team_member(): void
    {
        $admin = User::factory()->create();
        $spaceOnlyMember = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignSpaceRole($space, $spaceOnlyMember, 'editor');

        $response = $this->actingAs($admin)
            ->patchJson(route('mgmt.teams.users.update', [$team, $spaceOnlyMember->id]), [
                'role' => 'member',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $spaceOnlyMember->id)
            ->assertJsonPath('data.membership_origin', 'team')
            ->assertJsonPath('data.role', 'member');

        $this->assertTeamRole($team, $spaceOnlyMember, 'member');
        $this->assertSpaceRole($space, $spaceOnlyMember, 'editor');
    }

    #[Test]
    public function deleting_a_space_only_team_user_returns_a_clear_not_found_message(): void
    {
        $admin = User::factory()->create();
        $spaceOnlyMember = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignSpaceRole($space, $spaceOnlyMember, 'editor');

        $response = $this->actingAs($admin)
            ->deleteJson(route('mgmt.teams.users.destroy', [$team, $spaceOnlyMember->id]));

        $response->assertNotFound()
            ->assertJsonPath('message', 'User does not have a direct team membership.');

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $spaceOnlyMember->id,
        ]);
        $this->assertSpaceRole($space, $spaceOnlyMember, 'editor');
    }
}
