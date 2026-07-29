<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The closed-registration gate has to hold on every account-creation path, not
 * just the password form. The provider redirect is unauthenticated, so an
 * ungated social callback leaves a "closed" instance open to anyone holding an
 * account with the configured IdP.
 */
class SocialRegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $email, string $externalId = 'google-1'): void
    {
        // See SocialLoginLinkingTest: a real Socialite user, because the
        // controller reads getRaw(), which is not on the contract.
        $socialUser = new SocialiteTwoUser;
        $socialUser->map([
            'id' => $externalId,
            'email' => $email,
            'name' => 'Test User',
        ]);
        $socialUser->setRaw(['email_verified' => true]);

        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    #[Test]
    public function social_login_cannot_create_an_account_once_registration_is_closed(): void
    {
        config(['edition.edition' => 'self-hosted']);
        User::factory()->create();

        $this->fakeGoogleUser('stranger@example.com');

        $this->get(route('auth.social.callback', ['provider' => 'google']))
            ->assertRedirectContains('social_error=1');

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    #[Test]
    public function social_login_provisions_the_first_self_hosted_account(): void
    {
        config(['edition.edition' => 'self-hosted']);

        $this->fakeGoogleUser('owner@example.com');

        $this->get(route('auth.social.callback', ['provider' => 'google']));

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com']);
    }

    #[Test]
    public function saas_social_signup_stays_open_with_existing_accounts(): void
    {
        config(['edition.edition' => 'saas']);
        User::factory()->create();

        $this->fakeGoogleUser('newcomer@example.com');

        $this->get(route('auth.social.callback', ['provider' => 'google']));

        $this->assertDatabaseHas('users', ['email' => 'newcomer@example.com']);
    }

    /**
     * Existing users must still be able to sign in through the provider after
     * registration closes — the gate covers creation only.
     */
    #[Test]
    public function an_existing_account_can_still_sign_in_when_registration_is_closed(): void
    {
        config(['edition.edition' => 'self-hosted']);
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'email_verified_at' => now(),
            'source' => 'website',
        ]);

        $this->fakeGoogleUser('member@example.com');

        $this->get(route('auth.social.callback', ['provider' => 'google']));

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }
}
