<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserInviteAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_cannot_accept_someone_elses_invite_even_with_a_valid_token(): void
    {
        $owner = User::factory()->create();
        $wrongUser = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $owner, 'owner');
        $this->assignSpaceRole($space, $owner, 'owner');

        $this->actingAs($owner);
        $this->postJson(route('mgmt.spaces.invites.store', $space), [
            'email' => 'invitee@example.com',
            'role' => 'member',
            'expires_in_days' => 7,
        ])->assertCreated();

        /** @var Invite $invite */
        $invite = Invite::query()->firstOrFail();

        $this->actingAs($wrongUser);
        $this->postJson(route('mgmt.users.me.invites.accept', $invite), [
            'token' => $invite->token,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('space_user', [
            'space_id' => $space->id,
            'user_id' => $wrongUser->id,
        ]);

        $this->assertDatabaseHas('invites', [
            'id' => $invite->id,
            'accepted_at' => null,
        ]);
    }
}
