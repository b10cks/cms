<?php

namespace Tests\Feature\Auth;

use App\Enums\InstallProfile;
use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Setup\InstallState;
use App\Support\EditionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Soft-deleting the last account must not hand a populated instance to
     * whoever registers next.
     */
    #[Test]
    public function soft_deleting_the_last_account_does_not_reopen_registration(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $user = User::factory()->create();
        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);

        $this->postJson(route('auth.register'), $this->payload())
            ->assertForbidden();
    }

    /**
     * Once an account has been observed the answer is latched, so it survives
     * even the accounts themselves being removed.
     */
    #[Test]
    public function the_closed_state_is_latched_and_survives_account_removal(): void
    {
        config(['edition.edition' => 'self-hosted']);

        User::factory()->create();
        $this->assertFalse(EditionGate::registrationOpen());

        User::query()->forceDelete();

        $this->assertTrue(app(InstallState::class)->registrationClosed());
        $this->assertFalse(EditionGate::registrationOpen());
    }

    /**
     * A database blip on an installed instance must not reopen registration —
     * the whole point of the gate is that a stranger cannot claim the install.
     */
    #[Test]
    public function a_database_error_on_an_installed_instance_keeps_registration_closed(): void
    {
        config(['edition.edition' => 'self-hosted']);

        app(InstallState::class)->write(InstallProfile::STANDARD);

        // Any failure of the account query, from a connection cap to a failover.
        Schema::drop('users');

        $this->assertFalse(EditionGate::registrationOpen());
    }

    /**
     * The mirror case: before setup has migrated anything the query fails too,
     * and refusing there would brick the first boot.
     */
    #[Test]
    public function a_database_error_before_install_leaves_registration_open(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $this->assertFalse(app(InstallState::class)->exists());

        Schema::drop('users');

        $this->assertTrue(EditionGate::registrationOpen());
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
