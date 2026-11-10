<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adopting an existing local account on an email match is only safe when the
 * address is proof of ownership on both sides.
 */
class SocialLoginLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $email, string $externalId = 'google-1'): void
    {
        // A real Socialite user rather than a mock: the controller reads the
        // provider's raw payload through getRaw(), which is not on the
        // contract, so a contract mock would silently report "not verified".
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
    public function a_verified_local_account_is_adopted(): void
    {
        $user = User::factory()->create([
            'email' => 'person@corp.test',
            'email_verified_at' => now(),
            'source' => 'website',
        ]);

        $this->fakeGoogleUser('person@corp.test');

        $this->get(route('auth.social.callback', ['provider' => 'google']));

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }

    /**
     * An account created just-in-time by some team's SAML provider is marked
     * verified on the strength of that team's own assertion, so it is not
     * evidence that the address belongs to whoever holds the account.
     */
    #[Test]
    public function an_idp_provisioned_account_is_not_adopted(): void
    {
        User::factory()->create([
            'email' => 'victim@corp.test',
            'email_verified_at' => now(),
            'source' => 'saml:01JQTEAM00000000000000000',
        ]);

        $this->fakeGoogleUser('victim@corp.test');

        $this->get(route('auth.social.callback', ['provider' => 'google']))
            ->assertRedirectContains('social_error=1');

        $this->assertFalse(Auth::guard('web')->check());
    }

    #[Test]
    public function an_unverified_local_account_is_not_adopted(): void
    {
        User::factory()->create([
            'email' => 'pending@corp.test',
            'email_verified_at' => null,
            'source' => 'website',
        ]);

        $this->fakeGoogleUser('pending@corp.test');

        $this->get(route('auth.social.callback', ['provider' => 'google']))
            ->assertRedirectContains('social_error=1');

        $this->assertFalse(Auth::guard('web')->check());
    }

    #[Test]
    public function linking_a_provider_needs_a_step_up_and_a_url_signed_for_this_user(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $user = User::factory()->create()->fresh();
        $other = User::factory()->create()->fresh();

        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.test/o/oauth2'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->actingAs($user)
            ->get(route('auth.social.link.redirect', 'google'))
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.social-links.start', 'google'))
            ->assertStatus(423);

        $url = $this->actingAs($user)
            ->withHeader('x-password-confirmation', 'password')
            ->postJson(route('mgmt.users.me.social-links.start', 'google'), ['return' => '/account/settings/security'])
            ->assertOk()
            ->json('data.url');

        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($user)->get($url)->assertRedirect('https://accounts.google.test/o/oauth2');
    }

    #[Test]
    public function the_link_callback_refuses_a_flow_the_link_redirect_did_not_start(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $user = User::factory()->create()->fresh();

        // The public sign-in redirect leaves a valid OAuth state in the session.
        $this->actingAs($user)
            ->get(route('auth.social.link.callback', ['provider' => 'google', 'code' => 'c', 'state' => 's']))
            ->assertForbidden();

        $this->assertSame(0, $user->socialLinks()->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
