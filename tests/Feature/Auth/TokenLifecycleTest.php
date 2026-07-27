<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Personal access tokens are standing credentials with a lifecycle of their
 * own: they are listed in account settings and revoked there by hand. Nothing
 * else may sweep them away behind the user's back.
 */
class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function logging_out_leaves_personal_access_tokens_working(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('deploy script', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('auth.logout'))
            ->assertNoContent();

        $this->app['auth']->forgetGuards();

        $this->assertSame(1, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('mgmt.users.me.tokens.index'))
            ->assertOk();
    }

    #[Test]
    public function changing_the_password_leaves_personal_access_tokens_working(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);
        $token = $user->createToken('deploy script', ['*'])->plainTextToken;

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.password'), [
                'old_password' => 'old-password-1',
                'password' => 'new-password-2',
                'password_confirmation' => 'new-password-2',
            ])
            ->assertNoContent();

        $this->app['auth']->forgetGuards();

        $this->assertSame(1, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('mgmt.users.me.tokens.index'))
            ->assertOk();
    }

    /**
     * A token outlives the session that created it and survives a password
     * change, so minting one has to prove the owner is present now.
     */
    #[Test]
    public function minting_a_token_requires_the_password_when_no_second_factor_is_enrolled(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')])->fresh();

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.tokens.store'), [
                'name' => 'deploy script',
            ])
            ->assertStatus(423)
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->assertSame(0, $user->tokens()->count());

        $this->actingAs($user)
            ->withHeader('x-password-confirmation', 'correct-horse')
            ->postJson(route('mgmt.users.me.tokens.store'), [
                'name' => 'deploy script',
            ])
            ->assertCreated();

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function minting_a_token_requires_the_second_factor_when_one_is_enrolled(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-horse'),
            'two_factor_secret' => app(TwoFactorAuthService::class)->generateSecret(),
            'two_factor_enabled_at' => now(),
            'two_factor_backup_codes' => ['AAAAAAAA'],
        ])->fresh();

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.tokens.store'), ['name' => 'deploy script'])
            ->assertStatus(423)
            ->assertJsonPath('error_code', 'TOTP_VERIFICATION_REQUIRED');

        // The password alone is no longer enough once a second factor exists.
        $this->actingAs($user)
            ->withHeader('x-password-confirmation', 'correct-horse')
            ->postJson(route('mgmt.users.me.tokens.store'), ['name' => 'deploy script'])
            ->assertStatus(423);

        $this->assertSame(0, $user->tokens()->count());

        // A backup code is accepted, so losing the authenticator does not lock
        // the owner out of their own tokens.
        $this->actingAs($user)
            ->withHeader('x-totp-code', 'AAAAAAAA')
            ->postJson(route('mgmt.users.me.tokens.store'), ['name' => 'deploy script'])
            ->assertCreated();

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function a_user_can_list_and_revoke_their_own_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('deploy script', ['*']);
        $plainText = $token->plainTextToken;

        $this->actingAs($user)
            ->getJson(route('mgmt.users.me.tokens.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'deploy script');

        $this->actingAs($user)
            ->deleteJson(route('mgmt.users.me.tokens.destroy', $token->accessToken->getKey()))
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$plainText}")
            ->getJson(route('mgmt.users.me.tokens.index'))
            ->assertUnauthorized();
    }

    #[Test]
    public function a_user_cannot_revoke_another_users_token(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $owner->createToken('deploy script', ['*'])->accessToken;

        $this->actingAs($other)
            ->deleteJson(route('mgmt.users.me.tokens.destroy', $token->getKey()))
            ->assertForbidden();

        $this->assertSame(1, $owner->tokens()->count());
    }
}
