<?php

namespace Tests\Feature\Auth;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegisterWithInviteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registration_with_an_invite_requires_the_invited_email_address(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);

        $this->assignTeamRole($team, $owner, 'owner');
        $this->assignSpaceRole($space, $owner, 'owner');

        $this->actingAs($owner);
        $this->postJson(route('mgmt.spaces.invites.store', $space), [
            'email' => 'invited@example.com',
            'role' => 'member',
            'expires_in_days' => 7,
        ])->assertCreated();

        /** @var Invite $invite */
        $invite = Invite::query()->firstOrFail();

        auth()->guard('web')->logout();

        $response = $this->postJson(route('auth.register'), [
            'firstname' => 'Jamie',
            'lastname' => 'Wrong',
            'email' => 'someone-else@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'invite_id' => $invite->id,
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('users', [
            'email' => 'someone-else@example.com',
        ]);

        $this->assertDatabaseHas('invites', [
            'id' => $invite->id,
            'accepted_at' => null,
        ]);
    }
}
