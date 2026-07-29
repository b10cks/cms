<?php

namespace Tests\Feature\Auth;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $email = 'new-user@example.com'): array
    {
        return [
            'firstname' => 'Alex',
            'lastname' => 'Example',
            'email' => $email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ];
    }

    #[Test]
    public function self_hosted_registration_is_open_until_the_first_account_exists(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $this->postJson(route('auth.register'), $this->payload())
            ->assertCreated();
    }

    #[Test]
    public function self_hosted_registration_closes_once_an_account_exists(): void
    {
        config(['edition.edition' => 'self-hosted']);
        User::factory()->create();

        $this->postJson(route('auth.register'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new-user@example.com']);
    }

    #[Test]
    public function saas_registration_stays_open_with_existing_accounts(): void
    {
        config(['edition.edition' => 'saas']);
        User::factory()->create();

        $this->postJson(route('auth.register'), $this->payload())
            ->assertCreated();
    }

    #[Test]
    public function the_override_reopens_self_hosted_registration(): void
    {
        config([
            'edition.edition' => 'self-hosted',
            'edition.features.registration' => true,
        ]);
        User::factory()->create();

        $this->postJson(route('auth.register'), $this->payload())
            ->assertCreated();
    }

    #[Test]
    public function invite_registration_still_works_while_closed(): void
    {
        config(['edition.edition' => 'self-hosted']);

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

        $this->postJson(route('auth.register'), [
            ...$this->payload('invited@example.com'),
            'invite_id' => $invite->id,
            'invite_token' => $invite->token,
        ])->assertCreated();
    }
}
