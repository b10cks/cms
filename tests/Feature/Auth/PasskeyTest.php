<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\User\PasskeyAddedNotification;
use App\Notifications\User\PasskeyRemovedNotification;
use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * Passkeys sign a user in without password or TOTP, and a passkey
 * confirmation stands in for both at the step-up middleware. Every ceremony
 * here runs against a software authenticator, so the signatures are real.
 */
class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private const ORIGIN = 'https://app.b10cks.test';

    private const BACKUP_CODE = 'ZZZZZZZZ';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::ORIGIN]);
        Notification::fake();
        // Sanctum only starts a session for requests from a stateful domain.
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function userWithTwoFactor(): User
    {
        return $this->user([
            'two_factor_secret' => app(TwoFactorAuthService::class)->generateSecret(),
            'two_factor_enabled_at' => now(),
            'two_factor_backup_codes' => [self::BACKUP_CODE, 'AAAAAAAA'],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD), ...$attributes])->fresh();
    }

    private function register(User $user): SoftwareAuthenticator
    {
        $authenticator = new SoftwareAuthenticator(self::ORIGIN);

        [$header, $value] = $user->hasEnabledTwoFactor()
            ? ['x-totp-code', self::BACKUP_CODE]
            : ['x-password-confirmation', self::PASSWORD];

        $options = $this->actingAs($user)
            ->withHeader($header, $value)
            ->postJson(route('mgmt.users.me.passkeys.options'))
            ->assertOk()
            ->json();

        $this->withoutHeader('x-password-confirmation')
            ->withoutHeader('x-totp-code')
            ->postJson(route('mgmt.users.me.passkeys.store'), [
                'name' => 'Laptop',
                'credential' => $authenticator->create($options),
            ])
            ->assertCreated();

        return $authenticator;
    }

    private function signOut(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        // A new browser: the session no longer carries the old user's binding.
        $this->flushSession();
    }

    #[Test]
    public function adding_a_passkey_needs_a_step_up_and_notifies_the_owner(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.passkeys.options'))
            ->assertStatus(423)
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->register($user);

        $this->getJson(route('mgmt.users.me.passkeys.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Laptop');
        Notification::assertSentTo($user, PasskeyAddedNotification::class);
    }

    #[Test]
    public function a_passkey_cannot_be_stored_without_options_issued_to_the_session(): void
    {
        $user = $this->user();
        $credential = (new SoftwareAuthenticator(self::ORIGIN))->create([
            'challenge' => 'AAAA',
            'rp' => ['id' => 'app.b10cks.test'],
            'user' => ['id' => 'AAAA'],
        ]);

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.passkeys.store'), ['name' => 'Laptop', 'credential' => $credential])
            ->assertUnprocessable();

        $this->assertSame(0, $user->passkeys()->count());
    }

    #[Test]
    public function signing_in_with_a_passkey_skips_the_totp_code(): void
    {
        $user = $this->userWithTwoFactor();
        $authenticator = $this->register($user);
        $this->signOut();

        $options = $this->postJson(route('auth.passkeys.login.options'))->assertOk()->json();
        $this->assertSame([], $options['allowCredentials'] ?? []);

        $this->postJson(route('auth.passkeys.login'), ['credential' => $authenticator->get($options)])
            ->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->passkeys()->first()->last_used_at);
    }

    #[Test]
    public function a_sign_in_answer_cannot_be_replayed(): void
    {
        $user = $this->user();
        $authenticator = $this->register($user);
        $this->signOut();

        $options = $this->postJson(route('auth.passkeys.login.options'))->json();
        $credential = $authenticator->get($options);

        $this->postJson(route('auth.passkeys.login'), ['credential' => $credential])->assertOk();
        $this->signOut();

        $this->postJson(route('auth.passkeys.login'), ['credential' => $credential])->assertUnprocessable();
        $this->assertGuest('web');
    }

    #[Test]
    public function a_suspended_user_cannot_sign_in_with_a_passkey(): void
    {
        $user = $this->user();
        $authenticator = $this->register($user);
        $this->signOut();
        $user->forceFill(['suspended_at' => now()])->save();

        $options = $this->postJson(route('auth.passkeys.login.options'))->json();

        $this->postJson(route('auth.passkeys.login'), ['credential' => $authenticator->get($options)])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');
        $this->assertGuest('web');
    }

    #[Test]
    public function a_confirmation_passes_the_step_up_until_the_window_closes(): void
    {
        $user = $this->userWithTwoFactor();
        $authenticator = $this->register($user);
        // Let the TOTP grace of the registration step-up run out.
        $this->travel(31)->minutes();
        $this->postJson(route('mgmt.users.me.tokens.store'), ['name' => 'CI'])->assertStatus(423);

        $options = $this->postJson(route('auth.passkeys.confirm.options'))->assertOk()->json();
        $this->postJson(route('auth.passkeys.confirm'), ['credential' => $authenticator->get($options)])
            ->assertOk();

        // `password` middleware
        $this->postJson(route('auth.2fa.backup-codes.regenerate'))->assertOk();

        // `2fa` middleware, with TOTP enrolled
        $this->postJson(route('mgmt.users.me.tokens.store'), ['name' => 'CI'])->assertCreated();

        $this->travel(6)->minutes();

        $this->postJson(route('auth.2fa.backup-codes.regenerate'))->assertStatus(423);
    }

    #[Test]
    public function a_confirmation_needs_a_passkey_of_the_signed_in_user(): void
    {
        $other = $this->user();
        $othersAuthenticator = $this->register($other);
        $this->signOut();

        $user = $this->user();
        $this->register($user);

        $options = $this->actingAs($user)->postJson(route('auth.passkeys.confirm.options'))->json();
        $this->postJson(route('auth.passkeys.confirm'), ['credential' => $othersAuthenticator->get($options)])
            ->assertUnprocessable();

        $this->postJson(route('auth.2fa.backup-codes.regenerate'))->assertStatus(423);
    }

    #[Test]
    public function removing_a_passkey_needs_a_step_up_and_only_reaches_own_passkeys(): void
    {
        $other = $this->user();
        $this->register($other);
        $othersPasskey = $other->passkeys()->first();
        $this->signOut();

        $user = $this->user();
        $this->register($user);
        $passkey = $user->passkeys()->first();

        $this->actingAs($user)
            ->deleteJson(route('mgmt.users.me.passkeys.destroy', $passkey->id))
            ->assertStatus(423);

        $this->withHeader('x-password-confirmation', self::PASSWORD)
            ->deleteJson(route('mgmt.users.me.passkeys.destroy', $othersPasskey->id))
            ->assertNotFound();

        $this->withHeader('x-password-confirmation', self::PASSWORD)
            ->deleteJson(route('mgmt.users.me.passkeys.destroy', $passkey->id))
            ->assertNoContent();

        $this->assertSame(0, $user->passkeys()->count());
        $this->assertSame(1, $other->passkeys()->count());
        Notification::assertSentTo($user, PasskeyRemovedNotification::class);
    }
}
