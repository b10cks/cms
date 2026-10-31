<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\System\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** "Access until" on memberships and invites, and the sweep that ends it. */
class MembershipExpiryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_sets_and_clears_the_end_date_of_a_membership(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignTeamRole($team, $member, 'member');

        $this->actingAs($admin)
            ->putJson(route('mgmt.teams.users.expiry', [$team, $member]), ['access_until' => now()->addDays(5)->toDateString()])
            ->assertNoContent();

        $expiresAt = DB::table('team_user')->where('user_id', $member->id)->value('expires_at');
        $this->assertSame(now()->addDays(5)->endOfDay()->toDateTimeString(), $expiresAt);
        $this->assertTrue(AuditLog::query()->where('action', 'member_expiry_changed')->exists());

        $this->actingAs($admin)
            ->putJson(route('mgmt.teams.users.expiry', [$team, $member]), ['access_until' => null])
            ->assertNoContent();
        $this->assertNull(DB::table('team_user')->where('user_id', $member->id)->value('expires_at'));
    }

    #[Test]
    public function the_last_owner_cannot_get_an_end_date(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($root)
            ->putJson(route('mgmt.teams.users.expiry', [$team, $owner]), ['access_until' => now()->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    #[Test]
    public function an_accepted_invite_hands_its_end_date_to_the_membership(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner)
            ->postJson(route('mgmt.spaces.invites.store', $space), [
                'email' => 'guest@example.com',
                'role' => 'editor',
                'access_until' => now()->addDays(10)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.access_expires_at', now()->addDays(10)->endOfDay()->toIso8601String());

        $guest = User::factory()->create(['email' => 'guest@example.com', 'email_verified_at' => now()]);
        $invite = Invite::query()->where('email', 'guest@example.com')->firstOrFail();

        $this->actingAs($guest)
            ->postJson(route('mgmt.users.me.invites.accept', $invite), ['token' => $invite->token])
            ->assertSuccessful();

        $this->assertSame(
            now()->addDays(10)->endOfDay()->toDateTimeString(),
            DB::table('space_user')->where('user_id', $guest->id)->value('expires_at'),
        );
    }

    #[Test]
    public function the_sweep_ends_expired_access_but_keeps_the_last_owner(): void
    {
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);
        $owner = User::factory()->create();
        $guest = User::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');
        $this->assignSpaceRole($space, $guest, 'editor');
        DB::table('team_user')->update(['expires_at' => now()->subMinute()]);
        DB::table('space_user')->update(['expires_at' => now()->subMinute()]);

        $staleInvite = Invite::factory()->create(['space_id' => $space->id, 'access_expires_at' => now()->subDay()]);
        $openInvite = Invite::factory()->create(['space_id' => $space->id]);

        $this->artisan('memberships:expire')->assertSuccessful();

        $this->assertDatabaseMissing('space_user', ['user_id' => $guest->id]);
        $this->assertDatabaseHas('team_user', ['user_id' => $owner->id]);
        $this->assertModelMissing($staleInvite);
        $this->assertModelExists($openInvite);

        $removal = AuditLog::query()->where('action', 'member_removed')->firstOrFail();
        $this->assertSame('expired', $removal->metadata['reason']);
    }

    #[Test]
    public function the_people_list_has_an_expiring_segment(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');
        $soon = User::factory()->create();
        $later = User::factory()->create();
        $this->assignTeamRole($team, $soon, 'member');
        $this->assignTeamRole($team, $later, 'member');
        DB::table('team_user')->where('user_id', $soon->id)->update(['expires_at' => now()->addDays(3)]);
        DB::table('team_user')->where('user_id', $later->id)->update(['expires_at' => now()->addDays(40)]);

        $response = $this->actingAs($owner)
            ->getJson(route('mgmt.teams.people.index', [$team, 'segment' => 'expiring']))
            ->assertOk();

        $this->assertSame(1, $response->json('counts.expiring'));
        $this->assertSame([$soon->id], array_column($response->json('data'), 'user_id'));
        $this->assertNotNull($response->json('data.0.access_expires_at'));
    }
}
