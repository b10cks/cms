<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Invite;
use App\Models\Management\Role;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authorization_endpoint_returns_team_context_and_catalogs(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $this->assignTeamRole($team, $user, 'admin');
        $this->actingAs($user);

        $response = $this->getJson(route('mgmt.authorization.show', [
            'team_id' => $team->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.team.role_keys.0', 'admin')
            ->assertJsonPath('data.roles.team.0.scope', 'team');

        $this->assertContains('team.members.manage', $response->json('data.team.abilities'));
    }

    #[Test]
    public function authorization_endpoint_merges_inherited_team_and_direct_space_roles(): void
    {
        $user = User::factory()->create();
        $parentTeam = Team::factory()->create();
        $childTeam = Team::factory()->create(['parent_id' => $parentTeam->id]);
        $space = Space::factory()->create(['team_id' => $childTeam->id]);

        $this->assignTeamRole($parentTeam, $user, 'admin');
        $this->assignSpaceRole($space, $user, 'editor');
        $this->actingAs($user);

        $response = $this->getJson(route('mgmt.authorization.show', [
            'space_id' => $space->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.space.id', $space->id)
            ->assertJsonPath('data.space.team_role_keys.0', 'admin')
            ->assertJsonPath('data.space.space_role_key', 'editor');

        $abilities = $response->json('data.space.abilities');
        $this->assertContains('space.view', $abilities);
        $this->assertContains('content.manage', $abilities);
    }

    #[Test]
    public function accepting_a_space_invite_creates_only_space_membership(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $owner, 'owner');
        $this->assignSpaceRole($space, $owner, 'owner');

        $this->actingAs($owner);
        $createInviteResponse = $this->postJson(route('mgmt.spaces.invites.store', $space), [
            'email' => $invitee->email,
            'role' => 'member',
            'expires_in_days' => 7,
        ]);
        $createInviteResponse->assertCreated();

        /** @var Invite $invite */
        $invite = Invite::query()->firstOrFail();

        $this->actingAs($invitee);
        $acceptResponse = $this->postJson(route('mgmt.users.me.invites.accept', $invite), [
            'token' => $invite->token,
        ]);

        $acceptResponse->assertOk();
        $this->assertSpaceRole($space, $invitee, 'member');
        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
        ]);
    }

    #[Test]
    public function team_owner_can_manage_custom_space_roles_but_admin_cannot(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        // Custom roles define arbitrary ability sets, so managing them is an
        // owner-level capability (team.roles.manage), not part of member
        // management.
        $admin = User::factory()->create();
        $this->assignTeamRole($team, $admin, 'admin');
        $this->actingAs($admin)
            ->postJson(route('mgmt.teams.roles.space.store', $team), [
                'key' => 'translator',
                'name' => 'Translator',
                'level' => 110,
                'abilities' => ['space.view'],
            ])
            ->assertForbidden();

        $this->assignTeamRole($team, $user, 'owner');
        $this->actingAs($user);

        $createResponse = $this->postJson(route('mgmt.teams.roles.space.store', $team), [
            'key' => 'translator',
            'name' => 'Translator',
            'description' => 'Can translate and review content.',
            'level' => 110,
            'abilities' => ['space.view', 'content.view', 'content.manage'],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.key', 'translator')
            ->assertJsonPath('data.is_system', false);

        /** @var Role $role */
        $role = Role::query()->where('team_id', $team->id)->where('key', 'translator')->firstOrFail();

        $updateResponse = $this->patchJson(route('mgmt.teams.roles.space.update', [$team, $role]), [
            'abilities' => ['space.view', 'content.view', 'content.manage', 'comments.create'],
        ]);
        $updateResponse->assertOk();
        $this->assertContains('comments.create', $updateResponse->json('data.abilities'));

        $listResponse = $this->getJson(route('mgmt.teams.roles.space.index', $team));
        $listResponse->assertOk();
        $this->assertTrue(collect($listResponse->json('data'))->contains(fn (array $item) => $item['key'] === 'translator'));

        $deleteResponse = $this->deleteJson(route('mgmt.teams.roles.space.destroy', [$team, $role]));
        $deleteResponse->assertNoContent();
    }

    #[Test]
    public function team_index_includes_descendants_of_an_assigned_parent_team(): void
    {
        $user = User::factory()->create();
        $parentTeam = Team::factory()->create();
        $childTeam = Team::factory()->create(['parent_id' => $parentTeam->id]);

        $this->assignTeamRole($parentTeam, $user, 'member');
        $this->actingAs($user);

        $response = $this->getJson(route('mgmt.teams.index'));

        $response->assertOk();
        $teamIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($parentTeam->id, $teamIds);
        $this->assertContains($childTeam->id, $teamIds);
    }

    #[Test]
    public function selector_team_index_includes_teams_for_space_only_users_without_granting_team_access(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignSpaceRole($space, $user, 'editor');
        $this->actingAs($user);

        $response = $this->getJson(route('mgmt.teams.index', [
            'include_space_context' => 'true',
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $team->id)
            ->assertJsonPath('data.0.can_view_detail', false)
            ->assertJsonPath('data.0.can_create_space', false);

        $this->getJson(route('mgmt.teams.show', $team))->assertForbidden();
    }

    #[Test]
    public function team_member_cannot_manage_members_invites_or_space_roles(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $this->assignTeamRole($team, $user, 'member');
        $this->actingAs($user);

        $this->getJson(route('mgmt.teams.members.index', $team))->assertForbidden();
        $this->postJson(route('mgmt.teams.invites.store', $team), [
            'email' => 'new-user@example.com',
            'role' => 'member',
            'expires_in_days' => 7,
        ])->assertForbidden();
        $this->getJson(route('mgmt.teams.roles.space.index', $team))->assertForbidden();
    }

    #[Test]
    public function space_editor_cannot_manage_invites_or_billing(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignSpaceRole($space, $user, 'editor');
        $this->actingAs($user);

        $this->getJson(route('mgmt.spaces.invites.index', $space))->assertForbidden();
        $this->getJson(route('mgmt.spaces.subscriptions.index', $space))->assertForbidden();
    }
}
