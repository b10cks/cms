<?php

namespace Tests\Feature\Mgmt;

use App\Enums\MembershipSource;
use App\Enums\TeamDomainPolicy;
use App\Models\Management\Team;
use App\Models\Management\TeamDomain;
use App\Models\Management\TeamJoinRequest;
use App\Models\Management\TeamSamlProvider;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\Team\DnsTxtLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamDomainTest extends TestCase
{
    use RefreshDatabase;

    /** Fake DNS: tests write TXT records into `$dns->txt`. */
    private DnsTxtLookup $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new class extends DnsTxtLookup
        {
            /** @var array<string, array<int, string>> */
            public array $txt = [];

            public function records(string $domain): array
            {
                return $this->txt[$domain] ?? [];
            }
        };
        $this->app->instance(DnsTxtLookup::class, $this->dns);
    }

    private function ownedTeam(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        return [$team, $owner];
    }

    private function verifiedDomain(Team $team, string $domain = 'acme.test', TeamDomainPolicy $policy = TeamDomainPolicy::Request): TeamDomain
    {
        $record = $team->domains()->create([
            'domain' => $domain,
            'verification_token' => 'token',
            'policy' => $policy,
        ]);
        $record->forceFill(['verified_at' => now()])->save();

        return $record;
    }

    #[Test]
    public function owner_adds_a_normalised_domain_and_admins_may_not(): void
    {
        [$team, $owner] = $this->ownedTeam();
        $admin = User::factory()->create();
        $this->assignTeamRole($team, $admin, 'admin');

        $this->actingAs($admin)
            ->postJson(route('mgmt.teams.domains.store', $team), ['domain' => 'acme.test'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.domains.store', $team), ['domain' => ' https://Bücher.DE/ '])
            ->assertCreated()
            ->assertJsonPath('data.domain', 'xn--bcher-kva.de')
            ->assertJsonPath('data.display_domain', 'bücher.de')
            ->assertJsonPath('data.policy', 'request')
            ->assertJsonPath('data.default_role', 'member')
            ->assertJsonPath('data.verified_at', null);

        $this->assertTrue(AuditLog::query()->where('action', 'domain_added')->where('entity_id', $team->id)->exists());
    }

    #[Test]
    public function invalid_and_public_mail_domains_are_rejected(): void
    {
        [$team, $owner] = $this->ownedTeam();

        foreach (['not a domain', 'localhost', 'Gmail.com', '@outlook.com'] as $input) {
            $this->actingAs($owner)
                ->postJson(route('mgmt.teams.domains.store', $team), ['domain' => $input])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('domain');
        }
    }

    #[Test]
    public function verification_needs_the_txt_record(): void
    {
        [$team, $owner] = $this->ownedTeam();
        $domainId = $this->actingAs($owner)
            ->postJson(route('mgmt.teams.domains.store', $team), ['domain' => 'acme.test'])
            ->json('data.id');
        $domain = TeamDomain::findOrFail($domainId);

        $this->dns->txt['acme.test'] = ['v=spf1 -all'];
        $this->postJson(route('mgmt.teams.domains.verify', [$team, $domain]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domain');

        $this->dns->txt['acme.test'][] = '"'.$domain->txtRecord().'"';
        $this->postJson(route('mgmt.teams.domains.verify', [$team, $domain]))
            ->assertOk()
            ->assertJsonPath('data.id', $domain->id);

        $this->assertNotNull($domain->refresh()->verified_at);
        $this->assertTrue(AuditLog::query()->where('action', 'domain_verified')->where('entity_id', $team->id)->exists());
    }

    #[Test]
    public function a_domain_can_be_verified_by_one_team_only(): void
    {
        [$teamA, $ownerA] = $this->ownedTeam();
        [$teamB, $ownerB] = $this->ownedTeam();

        $claimB = $teamB->domains()->create(['domain' => 'acme.test', 'verification_token' => 'b-token']);
        $this->verifiedDomain($teamA);

        $this->actingAs($ownerB)
            ->postJson(route('mgmt.teams.domains.store', $teamB), ['domain' => 'other.test'])
            ->assertCreated();
        $this->actingAs($ownerB)
            ->postJson(route('mgmt.teams.domains.store', $teamB), ['domain' => 'ACME.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domain');

        $this->dns->txt['acme.test'] = [$claimB->txtRecord()];
        $this->actingAs($ownerB)
            ->postJson(route('mgmt.teams.domains.verify', [$teamB, $claimB]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domain');

        $this->assertNull($claimB->refresh()->verified_at);
        $this->assertSame(1, TeamDomain::query()->whereNotNull('verified_at')->where('domain', 'acme.test')->count());
    }

    #[Test]
    public function policy_changes_are_validated_and_audited(): void
    {
        [$team, $owner] = $this->ownedTeam();
        $domain = $this->verifiedDomain($team);
        $this->actingAs($owner);

        $this->patchJson(route('mgmt.teams.domains.update', [$team, $domain]), ['default_role' => 'owner'])
            ->assertJsonValidationErrors('default_role');
        $this->patchJson(route('mgmt.teams.domains.update', [$team, $domain]), ['policy' => 'require_sso'])
            ->assertJsonValidationErrors('policy');

        $this->patchJson(route('mgmt.teams.domains.update', [$team, $domain]), [
            'policy' => 'auto_join',
            'default_role' => 'admin',
        ])
            ->assertOk()
            ->assertJsonPath('data.policy', 'auto_join')
            ->assertJsonPath('data.default_role', 'admin');

        $log = AuditLog::query()->where('action', 'domain_policy_changed')->firstOrFail();
        $this->assertSame(['policy' => 'request', 'default_role' => 'member'], $log->old_values);
        $this->assertSame(['policy' => 'auto_join', 'default_role' => 'admin'], $log->new_values);

        $this->deleteJson(route('mgmt.teams.domains.destroy', [$team, $domain]))->assertNoContent();
        $this->assertModelMissing($domain);
        $this->assertTrue(AuditLog::query()->where('action', 'domain_removed')->exists());
    }

    #[Test]
    public function a_user_on_a_request_domain_can_ask_to_join_and_cancel(): void
    {
        [$team] = $this->ownedTeam();
        $this->verifiedDomain($team);
        $user = User::factory()->create(['email' => 'jane@acme.test']);

        $this->actingAs($user)
            ->getJson(route('mgmt.users.me.join-requests.index'))
            ->assertJsonPath('data.0.team.id', $team->id)
            ->assertJsonPath('data.0.request', null);

        $first = $this->postJson(route('mgmt.users.me.join-requests.store'), ['team_id' => $team->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');
        $second = $this->postJson(route('mgmt.users.me.join-requests.store'), ['team_id' => $team->id])
            ->json('data.id');
        $this->assertSame($first, $second);

        $this->deleteJson(route('mgmt.users.me.join-requests.destroy', $first))->assertNoContent();
        $this->assertDatabaseMissing('team_join_requests', ['id' => $first]);

        $otherTeam = Team::factory()->create();
        $this->postJson(route('mgmt.users.me.join-requests.store'), ['team_id' => $otherTeam->id])
            ->assertJsonValidationErrors('team_id');
    }

    #[Test]
    public function approval_respects_the_approvers_role_ceiling(): void
    {
        [$team] = $this->ownedTeam();
        $domain = $this->verifiedDomain($team);
        $admin = User::factory()->create();
        $this->assignTeamRole($team, $admin, 'admin');
        $user = User::factory()->create(['email' => 'jane@acme.test']);
        $joinRequest = TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $user->id, 'team_domain_id' => $domain->id]);

        $this->actingAs($admin)
            ->getJson(route('mgmt.teams.join-requests.index', $team))
            ->assertJsonPath('data.0.user.id', $user->id);

        $this->postJson(route('mgmt.teams.join-requests.approve', [$team, $joinRequest]), ['role' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id, 'user_id' => $user->id]);

        $this->postJson(route('mgmt.teams.join-requests.approve', [$team, $joinRequest]))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertTeamRole($team, $user, 'member');
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'source' => MembershipSource::Domain->value,
        ]);
        $this->assertTrue(AuditLog::query()->where('action', 'join_request_approved')->where('user_id', $admin->id)->exists());

        $this->postJson(route('mgmt.teams.join-requests.decline', [$team, $joinRequest]))
            ->assertJsonValidationErrors('join_request');
    }

    #[Test]
    public function a_declined_request_cannot_be_resent(): void
    {
        [$team, $owner] = $this->ownedTeam();
        $domain = $this->verifiedDomain($team);
        $user = User::factory()->create(['email' => 'jane@acme.test']);
        $joinRequest = TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $user->id, 'team_domain_id' => $domain->id]);

        $this->actingAs($owner)
            ->postJson(route('mgmt.teams.join-requests.decline', [$team, $joinRequest]))
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');
        $this->assertTrue(AuditLog::query()->where('action', 'join_request_declined')->exists());

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.join-requests.store'), ['team_id' => $team->id])
            ->assertJsonValidationErrors('team_id');
    }

    #[Test]
    public function require_sso_needs_an_enabled_saml_provider(): void
    {
        [$team, $owner] = $this->ownedTeam();
        $domain = $this->verifiedDomain($team);
        TeamSamlProvider::create([
            'team_id' => $team->id,
            'enabled' => true,
            'idp_entity_id' => 'https://idp.acme.test',
            'sso_url' => 'https://idp.acme.test/sso',
            'idp_x509_cert' => 'cert',
            'attribute_mapping' => ['email' => 'email'],
            'default_role' => 'member',
        ]);

        $this->actingAs($owner)
            ->patchJson(route('mgmt.teams.domains.update', [$team, $domain]), ['policy' => 'require_sso'])
            ->assertOk()
            ->assertJsonPath('data.policy', 'require_sso');
    }
}
