<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_root_user_can_impersonate_and_return(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $target = User::factory()->create();

        $this->actingAs($root);
        $response = $this->postJson(route('auth.impersonate.store'), ['userId' => $target->id])
            ->assertOk();

        $token = $response->json('access_token') ?? $response->json('token');
        $this->assertNotEmpty($token, 'Impersonation did not return an access token.');

        // Drop the session identity so the bearer token is the only credential.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson(route('auth.impersonate.destroy'))
            ->assertOk();
    }

    #[Test]
    public function a_non_root_user_cannot_start_an_impersonation(): void
    {
        $actor = User::factory()->create(['is_root' => false]);
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->postJson(route('auth.impersonate.store'), ['userId' => $target->id])
            ->assertForbidden();
    }

    /**
     * The real user used to be read out of the token's name, and users name
     * their own personal access tokens — so naming one after a root account
     * would have handed back a full-privilege token for that account.
     */
    #[Test]
    public function a_self_named_token_cannot_impersonate_its_way_to_root(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $attacker = User::factory()->create(['is_root' => false]);

        $forged = $attacker->createToken(
            ImpersonationService::TOKEN_NAME_PREFIX.$root->getRouteKey(),
            ['*']
        )->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$forged}")
            ->deleteJson(route('auth.impersonate.destroy'))
            ->assertForbidden();

        $this->assertSame(
            0,
            $root->tokens()->count(),
            'A token was minted for the root user from a forged token name.'
        );
    }
}
