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
        $root = $this->elevate(User::factory()->create(['is_root' => true]));
        $target = User::factory()->create();

        $this->actingAs($root);
        $response = $this->postJson(route('auth.impersonate.store'), ['userId' => $target->id, 'reason' => 'Support ticket'])
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
            ->postJson(route('auth.impersonate.store'), ['userId' => $target->id, 'reason' => 'Support ticket'])
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
        $root = $this->elevate(User::factory()->create(['is_root' => true]));
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

    #[Test]
    public function starting_an_impersonation_needs_an_elevation_and_a_reason(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $target = User::factory()->create();

        $this->actingAs($root)
            ->postJson(route('auth.impersonate.store'), ['userId' => $target->id, 'reason' => 'Support ticket'])
            ->assertForbidden();

        $this->elevate($root);

        $this->postJson(route('auth.impersonate.store'), ['userId' => $target->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function root_accounts_and_suspended_users_cannot_be_impersonated(): void
    {
        $root = $this->elevate(User::factory()->create(['is_root' => true]));
        $otherRoot = User::factory()->create(['is_root' => true]);
        $suspended = User::factory()->create(['suspended_at' => now()]);

        $this->actingAs($root);

        foreach ([$otherRoot, $root, $suspended] as $target) {
            $this->postJson(route('auth.impersonate.store'), ['userId' => $target->id, 'reason' => 'Support ticket'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('userId');
        }
    }

    #[Test]
    public function an_api_impersonation_token_expires_after_an_hour_and_is_audited(): void
    {
        $root = $this->elevate(User::factory()->create(['is_root' => true]));
        $target = User::factory()->create();

        $token = $this->actingAs($root)
            ->postJson(route('auth.impersonate.store'), ['userId' => $target->id, 'reason' => 'Ticket #12'])
            ->assertOk()
            ->json('access_token');

        $this->assertDatabaseHas('impersonations', ['impersonator_id' => $root->id, 'user_id' => $target->id, 'reason' => 'Ticket #12']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'root.impersonation.started', 'user_id' => $root->id]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('mgmt.users.me.show'))
            ->assertOk()
            ->assertJsonPath('data.id', $target->id);

        $this->travel(61)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('mgmt.users.me.show'))
            ->assertUnauthorized();
    }

    #[Test]
    public function a_browser_impersonation_switches_the_session_and_hands_it_back(): void
    {
        $root = $this->elevate(User::factory()->create(['is_root' => true]));
        $target = User::factory()->create();
        $this->withHeader('Referer', 'http://localhost/');

        $this->actingAs($root)
            ->postJson(route('auth.impersonate.store'), ['userId' => $target->id, 'reason' => 'Ticket #13'])
            ->assertOk()
            ->assertJsonPath('data.user_id', $target->id)
            ->assertJsonMissingPath('access_token');

        $this->freshRequest();
        $this->getJson(route('mgmt.users.me.show'))->assertJsonPath('data.id', $target->id);
        $this->getJson(route('mgmt.authorization.show'))
            ->assertJsonPath('data.impersonation.reason', 'Ticket #13')
            ->assertJsonPath('data.impersonation.impersonator.id', $root->id);

        // Blocked while impersonating, as before.
        $this->postJson(route('mgmt.users.me.password'), [])->assertForbidden();

        $this->deleteJson(route('auth.impersonate.destroy'))->assertNoContent();

        $this->freshRequest();
        $this->getJson(route('mgmt.users.me.show'))->assertJsonPath('data.id', $root->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'root.impersonation.ended', 'user_id' => $root->id]);
    }

    #[Test]
    public function a_lapsed_browser_impersonation_returns_the_session_to_the_operator(): void
    {
        $root = $this->elevate(User::factory()->create(['is_root' => true]), 120);
        $target = User::factory()->create();
        $this->withHeader('Referer', 'http://localhost/');

        $this->actingAs($root)
            ->postJson(route('auth.impersonate.store'), ['userId' => $target->id, 'reason' => 'Ticket #14'])
            ->assertOk();

        $this->travel(61)->minutes();
        $this->freshRequest();

        $this->getJson(route('mgmt.users.me.show'))->assertJsonPath('data.id', $root->id);
        $this->assertNotNull(\App\Models\Management\Impersonation::query()->firstOrFail()->ended_at);
    }

    /**
     * Guards and the default guard outlive a request inside a test, while in
     * production each request resolves the user from its session again.
     */
    private function freshRequest(): void
    {
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
    }
}
