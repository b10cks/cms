<?php

namespace Tests\Feature\Auth;

use App\Enums\MembershipSource;
use App\Models\Management\Team;
use App\Models\Management\TeamSamlProvider;
use App\Models\User;
use App\Services\Auth\SamlLoginService;
use App\Services\Team\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A team's identity provider may sign an assertion for any address its owner
 * likes, so who it is allowed to vouch for is the whole security boundary.
 */
class SamlLoginTest extends TestCase
{
    use RefreshDatabase;

    private function provider(Team $team, bool $allowJit = false): TeamSamlProvider
    {
        return TeamSamlProvider::create([
            'team_id' => $team->id,
            'enabled' => true,
            'idp_entity_id' => 'https://idp.attacker.test/metadata',
            'sso_url' => 'https://idp.attacker.test/sso',
            'idp_x509_cert' => 'irrelevant-for-this-test',
            'attribute_mapping' => ['email' => 'email', 'external_id' => 'nameId'],
            'allow_jit' => $allowJit,
            'default_role' => 'member',
        ]);
    }

    private function assertion(string $email, string $externalId = 'ext-1'): Auth
    {
        $auth = Mockery::mock(Auth::class);
        $auth->shouldReceive('processResponse')->andReturnNull();
        $auth->shouldReceive('getErrors')->andReturn([]);
        $auth->shouldReceive('isAuthenticated')->andReturnTrue();
        $auth->shouldReceive('getAttributes')->andReturn([
            'email' => [$email],
            'nameId' => [$externalId],
        ]);
        $auth->shouldReceive('getNameId')->andReturn($email);
        $auth->shouldReceive('getSessionIndex')->andReturn('session-1');

        return $auth;
    }

    /**
     * The attack: every registered user owns a personal team and may configure
     * a SAML provider on it, and a team owner may attach any user id to their
     * own team. That attached membership must not become the claim that lets
     * the attacker's IdP assert the victim's address.
     */
    #[Test]
    public function an_attached_member_is_not_vouchable_by_the_teams_idp(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create(['email' => 'victim@corp.test']);

        $team = app(TeamService::class)->createTeam(['name' => 'Attacker Team'], $attacker);
        app(TeamService::class)->attachUser($team, $victim->id, 'member');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $victim->id,
            'source' => MembershipSource::Direct->value,
        ]);

        $provider = $this->provider($team);

        $this->expectException(ValidationException::class);

        app(SamlLoginService::class)->completeLogin(
            $provider,
            $this->assertion('victim@corp.test'),
            null,
        );
    }

    #[Test]
    public function a_member_who_accepted_an_invite_can_sign_in_through_the_teams_idp(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@corp.test']);

        $team = app(TeamService::class)->createTeam(['name' => 'Corp'], $owner);
        app(\App\Services\Auth\MembershipService::class)
            ->assignTeamRole($team, $member, 'member', MembershipSource::Invite);

        $user = app(SamlLoginService::class)->completeLogin(
            $this->provider($team),
            $this->assertion('member@corp.test'),
            null,
        );

        $this->assertTrue($user->is($member));
    }

    #[Test]
    public function the_team_owner_can_always_sign_in_through_their_own_idp(): void
    {
        $owner = User::factory()->create(['email' => 'owner@corp.test']);
        $team = app(TeamService::class)->createTeam(['name' => 'Corp'], $owner);

        // Every login re-applies the provider's role, and demoting the only
        // owner is refused by MembershipGuard — so the provider has to map
        // them back to owner for the sign-in to get that far.
        $provider = $this->provider($team);
        $provider->update(['default_role' => 'owner']);

        $user = app(SamlLoginService::class)->completeLogin(
            $provider,
            $this->assertion('owner@corp.test'),
            null,
        );

        $this->assertTrue($user->is($owner));
    }

    /**
     * An unrelated account is not reachable either — that was the hole the
     * team scoping closed, and it stays closed.
     */
    #[Test]
    public function a_non_member_cannot_be_asserted_without_jit(): void
    {
        $attacker = User::factory()->create();
        User::factory()->create(['email' => 'stranger@corp.test']);

        $team = app(TeamService::class)->createTeam(['name' => 'Attacker Team'], $attacker);

        $this->expectException(ValidationException::class);

        app(SamlLoginService::class)->completeLogin(
            $this->provider($team),
            $this->assertion('stranger@corp.test'),
            null,
        );
    }

    /**
     * JIT provisioning marks the new account verified on the strength of the
     * assertion alone, so it must not be adoptable by a later social sign-in.
     */
    #[Test]
    public function a_jit_provisioned_account_is_recorded_as_idp_sourced(): void
    {
        $attacker = User::factory()->create();
        $team = app(TeamService::class)->createTeam(['name' => 'Attacker Team'], $attacker);

        $user = app(SamlLoginService::class)->completeLogin(
            $this->provider($team, allowJit: true),
            $this->assertion('newcomer@corp.test'),
            null,
        );

        $this->assertSame("saml:{$team->id}", $user->source);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'source' => MembershipSource::Saml->value,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
