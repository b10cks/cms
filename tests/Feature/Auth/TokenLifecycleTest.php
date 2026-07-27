<?php

namespace Tests\Feature\Auth;

use App\Models\User;
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
