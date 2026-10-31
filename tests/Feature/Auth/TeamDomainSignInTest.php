<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipSource;
use App\Enums\TeamDomainPolicy;
use App\Models\Management\Team;
use App\Models\Management\TeamSamlProvider;
use App\Models\User;
use App\Services\Auth\RoleService;
use App\Services\Auth\SamlLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Mockery;
use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** What a verified team domain means when someone with a matching email signs in. */
class TeamDomainSignInTest extends TestCase
{
    use RefreshDatabase;

    private function teamWithDomain(TeamDomainPolicy $policy, string $defaultRole = 'member', bool $sso = false): Team
    {
        $team = Team::factory()->create(['name' => 'Acme']);
        $team->domains()->create([
            'domain' => 'acme.test',
            'verification_token' => 'token',
            'policy' => $policy,
            'default_role_id' => app(RoleService::class)->resolveTeamRole($defaultRole)->id,
        ])->forceFill(['verified_at' => now()])->save();

        if ($sso) {
            TeamSamlProvider::create([
                'team_id' => $team->id,
                'enabled' => true,
                'idp_entity_id' => 'https://idp.acme.test',
                'sso_url' => 'https://idp.acme.test/sso',
                'idp_x509_cert' => 'cert',
                'attribute_mapping' => ['email' => 'email', 'external_id' => 'nameId'],
                'allow_jit' => false,
                'default_role' => 'member',
            ]);
        }

        return $team;
    }

    private function login(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('auth.token.login'), ['email' => $email, 'password' => 'password']);
    }

    #[Test]
    public function auto_join_adds_the_user_with_the_default_role_on_sign_in(): void
    {
        $team = $this->teamWithDomain(TeamDomainPolicy::AutoJoin, 'admin');
        $user = User::factory()->create(['email' => 'jane@acme.test']);

        $this->login('jane@acme.test')->assertOk();

        $this->assertTeamRole($team, $user, 'admin');
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'source' => MembershipSource::Domain->value,
        ]);
    }

    #[Test]
    public function auto_join_waits_for_a_verified_email(): void
    {
        $team = $this->teamWithDomain(TeamDomainPolicy::AutoJoin);
        $user = User::factory()->unverified()->create(['email' => 'jane@acme.test']);

        $this->login('jane@acme.test')->assertStatus(409);
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id, 'user_id' => $user->id]);

        $this->get(URL::signedRoute('verification.verify', [
            'id' => $user->getRouteKey(),
            'hash' => sha1($user->email),
        ]))->assertRedirect();

        $this->assertTeamRole($team, $user, 'member');
    }

    #[Test]
    public function the_request_policy_never_joins_on_its_own(): void
    {
        $team = $this->teamWithDomain(TeamDomainPolicy::Request);
        $user = User::factory()->create(['email' => 'jane@acme.test']);

        $this->login('jane@acme.test')->assertOk();

        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id, 'user_id' => $user->id]);
        $this->assertDatabaseCount('team_join_requests', 0);
    }

    #[Test]
    public function require_sso_refuses_password_sign_in_and_signup(): void
    {
        $team = $this->teamWithDomain(TeamDomainPolicy::RequireSso, sso: true);
        User::factory()->create(['email' => 'jane@acme.test']);

        $this->login('jane@acme.test')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SSO_REQUIRED')
            ->assertJsonPath('team.id', $team->id)
            ->assertJsonPath('sso_url', route('auth.saml.redirect', $team));
        $this->assertGuest('web');

        $this->postJson(route('auth.register'), [
            'firstname' => 'Joe',
            'lastname' => 'Doe',
            'email' => 'joe@ACME.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertForbidden()->assertJsonPath('error_code', 'SSO_REQUIRED');
        $this->assertDatabaseMissing('users', ['email' => 'joe@acme.test']);
    }

    #[Test]
    public function require_sso_exempts_root_and_lapses_without_an_enabled_provider(): void
    {
        $team = $this->teamWithDomain(TeamDomainPolicy::RequireSso, sso: true);
        User::factory()->create(['email' => 'root@acme.test', 'is_root' => true]);
        User::factory()->create(['email' => 'jane@acme.test']);

        $this->login('root@acme.test')->assertOk();
        $this->postJson(route('auth.logout'));

        $team->samlProvider()->update(['enabled' => false]);
        $this->login('jane@acme.test')->assertOk();
    }

    #[Test]
    public function the_teams_idp_adopts_verified_accounts_on_its_domain(): void
    {
        $team = $this->teamWithDomain(TeamDomainPolicy::RequireSso, sso: true);
        $jane = User::factory()->create(['email' => 'jane@acme.test']);
        User::factory()->unverified()->create(['email' => 'squatter@acme.test']);
        $provider = $team->samlProvider()->firstOrFail();

        $user = app(SamlLoginService::class)->completeLogin($provider, $this->assertion('jane@acme.test'), null);

        $this->assertTrue($user->is($jane));
        $this->assertTeamRole($team, $jane, 'member');

        $this->expectException(ValidationException::class);
        app(SamlLoginService::class)->completeLogin($provider, $this->assertion('squatter@acme.test', 'ext-2'), null);
    }

    private function assertion(string $email, string $externalId = 'ext-1'): Auth
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldReceive('processResponse')->andReturnNull();
        $auth->shouldReceive('getErrors')->andReturn([]);
        $auth->shouldReceive('isAuthenticated')->andReturnTrue();
        $auth->shouldReceive('getAttributes')->andReturn(['email' => [$email], 'nameId' => [$externalId]]);
        $auth->shouldReceive('getNameId')->andReturn($email);
        $auth->shouldReceive('getSessionIndex')->andReturn('session-1');

        return $auth;
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
