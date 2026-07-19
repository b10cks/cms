<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\System\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamMembershipHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── Role-level ceiling ────────────────────────────────────────────────

    #[Test]
    public function team_admin_cannot_invite_an_owner_but_can_invite_an_admin(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $admin, 'admin');

        $this->actingAs($admin)
            ->postJson(route('mgmt.teams.invites.store', $team), [
                'email' => 'new.owner@example.com',
                'role' => 'owner',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($admin)
            ->postJson(route('mgmt.teams.invites.store', $team), [
                'email' => 'new.admin@example.com',
                'role' => 'admin',
            ])
            ->assertCreated();
    }

    #[Test]
    public function team_admin_cannot_promote_a_member_to_owner(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignTeamRole($team, $member, 'member');

        $this->actingAs($admin)
            ->patchJson(route('mgmt.teams.members.update', [$team, $member]), ['role' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    #[Test]
    public function team_admin_cannot_demote_or_remove_an_owner(): void
    {
        $admin = User::factory()->create();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');
        $this->assignTeamRole($team, $admin, 'admin');

        $this->actingAs($admin)
            ->patchJson(route('mgmt.teams.members.update', [$team, $owner]), ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->actingAs($admin)
            ->deleteJson(route('mgmt.teams.members.destroy', [$team, $owner]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    #[Test]
    public function space_admin_cannot_grant_the_space_owner_role(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);
        $this->assignSpaceRole($space, $admin, 'admin');

        $this->actingAs($admin)
            ->postJson(route('mgmt.spaces.invites.store', $space), [
                'email' => 'new.owner@example.com',
                'role' => 'owner',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    // ── Last-owner protection ─────────────────────────────────────────────

    #[Test]
    public function the_last_team_owner_cannot_be_demoted_or_removed(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner)
            ->patchJson(route('mgmt.teams.members.update', [$team, $owner]), ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($owner)
            ->deleteJson(route('mgmt.teams.members.destroy', [$team, $owner]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        // With a second owner in place the original owner can step down.
        $secondOwner = User::factory()->create();
        $this->assignTeamRole($team, $secondOwner, 'owner');

        $this->actingAs($owner)
            ->patchJson(route('mgmt.teams.members.update', [$team, $owner]), ['role' => 'member'])
            ->assertNoContent();
    }

    // ── Invite hygiene ────────────────────────────────────────────────────

    #[Test]
    public function a_new_invite_supersedes_an_expired_one_for_the_same_address(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $expired = Invite::factory()->create([
            'team_id' => $team->id,
            'space_id' => null,
            'invited_by' => $owner->id,
            'email' => 'invitee@example.com',
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.invites.store', $team), [
                'email' => 'invitee@example.com',
                'role' => 'member',
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('invites', ['id' => $expired->id]);
        $this->assertSame(1, Invite::query()->where('team_id', $team->id)->count());
    }

    #[Test]
    public function pending_invites_block_duplicates_case_insensitively(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.invites.store', $team), [
                'email' => 'invitee@example.com',
                'role' => 'member',
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.invites.store', $team), [
                'email' => 'Invitee@Example.com',
                'role' => 'member',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function resending_extends_the_expiry_window(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $invite = Invite::factory()->create([
            'team_id' => $team->id,
            'space_id' => null,
            'invited_by' => $owner->id,
            'expires_at' => now()->addHours(2),
        ]);

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.invites.resend', [$team, $invite]))
            ->assertOk();

        $this->assertTrue($invite->refresh()->expires_at->gt(now()->addDays(6)));
    }

    #[Test]
    public function resending_is_rate_limited(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $invite = Invite::factory()->create([
            'team_id' => $team->id,
            'space_id' => null,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDay(),
        ]);

        foreach (range(1, 5) as $i) {
            $this->actingAs($owner)
                ->postJson(route('mgmt.teams.invites.resend', [$team, $invite]))
                ->assertOk();
        }

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.invites.resend', [$team, $invite]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invite');
    }

    // ── Decline flow ──────────────────────────────────────────────────────

    #[Test]
    public function an_invitee_can_decline_and_a_declined_invite_cannot_be_accepted(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $inviter, 'owner');

        $invite = Invite::factory()->create([
            'team_id' => $team->id,
            'space_id' => null,
            'invited_by' => $inviter->id,
            'email' => 'invitee@example.com',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($invitee)
            ->postJson(route('mgmt.users.me.invites.decline', $invite))
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $this->actingAs($invitee)
            ->postJson(route('mgmt.users.me.invites.accept', $invite), ['token' => $invite->token])
            ->assertUnprocessable();

        // Declined invites disappear from the people directory.
        $people = $this->actingAs($inviter)
            ->getJson(route('mgmt.teams.people.index', $team))
            ->assertOk()
            ->json('data');

        $this->assertNull(collect($people)->firstWhere('invite_id', $invite->id));
    }

    #[Test]
    public function a_stranger_cannot_decline_someone_elses_invite(): void
    {
        $inviter = User::factory()->create();
        $stranger = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $inviter, 'owner');

        $invite = Invite::factory()->create([
            'team_id' => $team->id,
            'space_id' => null,
            'invited_by' => $inviter->id,
            'email' => 'invitee@example.com',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($stranger)
            ->postJson(route('mgmt.users.me.invites.decline', $invite))
            ->assertForbidden();
    }

    #[Test]
    public function an_authenticated_invitee_can_accept_without_a_token(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $inviter, 'owner');

        $invite = Invite::factory()->create([
            'team_id' => $team->id,
            'space_id' => null,
            'invited_by' => $inviter->id,
            'email' => 'Invitee@Example.com',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($invitee)
            ->postJson(route('mgmt.users.me.invites.accept', $invite))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertTrue($team->users()->where('users.id', $invitee->id)->exists());
    }

    // ── Audit trail ───────────────────────────────────────────────────────

    #[Test]
    public function membership_changes_write_audit_log_entries(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        AuditLog::query()->delete();

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.users.store', $team), [
                'user_id' => $member->id,
                'role' => 'member',
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->patchJson(route('mgmt.teams.members.update', [$team, $member]), ['role' => 'admin'])
            ->assertNoContent();

        $this->actingAs($owner)
            ->deleteJson(route('mgmt.teams.members.destroy', [$team, $member]))
            ->assertNoContent();

        $actions = AuditLog::query()
            ->where('entity_type', Team::class)
            ->where('entity_id', $team->id)
            ->pluck('action');

        $this->assertTrue($actions->contains('member_added'));
        $this->assertTrue($actions->contains('member_role_changed'));
        $this->assertTrue($actions->contains('member_removed'));
    }
}
