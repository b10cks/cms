<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamSamlProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string CERTIFICATE = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDDTCCAfWgAwIBAgIUdnFjt2ZV53YCJJRCM4dWTr1md+UwDQYJKoZIhvcNAQEL
BQAwFjEUMBIGA1UEAwwLYjEwY2tzLnRlc3QwHhcNMjYwNDI1MTkyOTU3WhcNMjcw
NDI1MTkyOTU3WjAWMRQwEgYDVQQDDAtiMTBja3MudGVzdDCCASIwDQYJKoZIhvcN
AQEBBQADggEPADCCAQoCggEBAM7FTC9OMqr7FhyoowbocbZV1aTlH+ZNP765JBBa
uTmcFIGR4HguhNPPFsYE3dxvWY+nETQRckDTssGZKkutHG15zZ7IXV7zZ9AFgriu
SHH02DHakjFaFKSAcg/oL8vqxyGgmeTaEADcyLTVzEmaWGo4iq9EvZMgLtm4OATE
LWdMh0fw+QMMow7cCl3wh0vc/w8hvOVpZW2WxYn6Rka9xYlmMw3qoKnL68a9bh6A
QVt+l0nazWK+pxuOT427ofHqPrb9nU4E6/dGJRKrhnzLmU6jzRnryihHciBW5xkm
WLWGgvsK+VsCo4rSRXO2I2kThfeXj5ub/z3FE+lQsicfjh0CAwEAAaNTMFEwHQYD
VR0OBBYEFOUd0WVnUY5U+50MSC1465hnagDBMB8GA1UdIwQYMBaAFOUd0WVnUY5U
+50MSC1465hnagDBMA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQELBQADggEB
AJfjL/c6vSXLPe3rWjEp1NFeQASd7UyTdRcqAEKVNkQry9imCy6SFrrR0jpBB5t1
vs5Vw+h3G4yliDhZL9bdp/vxLrqPpyWlqPO9huskqXKtUI/jipSbwERbqsZqTvgi
KsfjVKdqbAl06JzyL0mwZ0TN0ynvlgHxFWUWJ4iOx8Zx0zV+ELgOfgl7nCYxJwBn
IeDp2lLjU9oICouG1CsEkbJelUIlTY+sAohqMnZuoShcqioGbCPl8YnfBDRW5+Ya
rB0fOX1VtQXD7saDOGWubveD+uLdgO7PzpAk3ImVevIxvF0aSvPAmfD5bVQbz6UN
mNWWhcRLOxAq1I9qIh2ZQVI=
-----END CERTIFICATE-----
PEM;

    private const string PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDOxUwvTjKq+xYc
qKMG6HG2VdWk5R/mTT++uSQQWrk5nBSBkeB4LoTTzxbGBN3cb1mPpxE0EXJA07LB
mSpLrRxtec2eyF1e82fQBYK4rkhx9Ngx2pIxWhSkgHIP6C/L6schoJnk2hAA3Mi0
1cxJmlhqOIqvRL2TIC7ZuDgExC1nTIdH8PkDDKMO3Apd8IdL3P8PIbzlaWVtlsWJ
+kZGvcWJZjMN6qCpy+vGvW4egEFbfpdJ2s1ivqcbjk+Nu6Hx6j62/Z1OBOv3RiUS
q4Z8y5lOo80Z68ooR3IgVucZJli1hoL7CvlbAqOK0kVztiNpE4X3l4+bm/89xRPp
ULInH44dAgMBAAECggEAKRiCk1Zd8Ki11N1ZhZp2W1CFBWB6rhnFZSFD/zIg5UfV
tYjqTcilIrnio1m9RL4m1UvVuf0LscHBogPQqbjO0R1n1jqpgCEtjWVC/XS6Nlf9
Di+MZd2rA6T5xpqVwVg42sCiRZ9nldxL0dE6aJiSIQ561en6Kb84QJKCI8Rf58ss
pjU6SEhpEEzSt5/fceNThO7cV74LZDzcb+KmSut6JteRkhdG3ZHWE3ss8xSRfbN0
/2APInA57qdK7g6L2LbiJsxmkq9ZDNZXNZawF/582TIOrw+0yI7QyKyGrgiCeHQz
attGuk4rjHsfuvxVWcW5tiw9en8cX0mB2K7iVREP4QKBgQD9n5kRUzKum0l3A72/
fWM+6XmjErAGWV5R6MhcL6uDdSoLiQ4wVgVvMPguOx8ebC24aoR/uSJfSRD7uDw8
dQaGh4O69OjlZHOOTUJg9rBvc2zWFFUSIU3IBQ4/wQ+LMPZ69luxWjNBhlcwlS80
yewX11IxT+7O54MDl6pvri017QKBgQDQtU61DwLWdKfevsPN39qgMd+yKEn4pdwV
3dhCo4WGp5ClyApmYq3CZhXmbdmBJk7htLJ6svzwtZWUHVKgiE3ImkOGP7fnoocy
O9YiBZu2RNsB41Wb6EiyAfGrRhAXSfYqItbuWdf28Nzp7HNUqXN7D2s378ibvymQ
DgazSTWy8QKBgF8fcMVystuSGmes24nqeUKrRpfG9oYrFpkZ+au5pVZUp0RUTyIJ
4Vfmwe509iLu5+b27GMLCL08JkaCvvTd32itgtan7IG8vypsB61eWKY0YGmajp2S
KB4Q51s6CZ5m6ssLgzBtaDP3MtRh36ao5Qe8FnOwSx0G77h1NdNVPFexAoGAbuc5
SLsOTfk1Twbds2N1oFSAQwJntEomdjQpe9e613/pPD7dT+S14qwujQDoaFl75zIG
+W5tPFexgUBHrOhhNOzMXuUzC1JxNv9W3UNPp/5Uxl8QGcXIA1dHHTUgzc9OkLts
rMFvIliBe4hbDKzyoXzjA5lWZ3SgWF0rsE+BynECgYEA4Zd7TY8dizTCOoY0i86w
q2gROaARSPbJYPfceS+5UU+EnCmGncb1AM8s2B03Zi4Rs8LdQZPe2wGbdTqeScRr
7BON6mUrMnU9SF3Z9wfBBHVkIhokRA23L99kZOHCjQOOAXS21dSIxrm8N8CnQNvG
KHxKX5RZivA1DY/b5uInfxk=
-----END PRIVATE KEY-----
PEM;

    #[Test]
    public function owner_can_read_saml_provider_defaults(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $response = $this->actingAs($owner)
            ->getJson(route('mgmt.teams.saml-provider.show', $team));

        $response->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('defaults.default_role', 'member')
            ->assertJsonPath('defaults.attribute_mapping.email', 'email')
            ->assertJsonPath('defaults.links.acs_url', route('auth.saml.acs', $team));
    }

    #[Test]
    public function only_team_owner_can_manage_saml_provider(): void
    {
        $admin = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $admin, 'admin');

        $response = $this->actingAs($admin)
            ->putJson(route('mgmt.teams.saml-provider.upsert', $team), $this->payload());

        $response->assertForbidden();
        $this->assertDatabaseMissing('team_saml_providers', ['team_id' => $team->id]);
    }

    #[Test]
    public function owner_can_create_and_update_saml_provider(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $payload = $this->payload();

        $response = $this->actingAs($owner)
            ->putJson(route('mgmt.teams.saml-provider.upsert', $team), $payload);

        $response->assertOk()
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.has_sp_private_key', true)
            ->assertJsonMissingPath('data.sp_private_key');

        $this->assertDatabaseHas('team_saml_providers', [
            'team_id' => $team->id,
            'enabled' => true,
            'idp_entity_id' => $payload['idp_entity_id'],
        ]);

        $stored = $team->samlProvider()->firstOrFail();
        $this->assertSame($payload['idp_x509_cert'], $stored->idp_x509_cert);
        $this->assertNotSame($payload['idp_x509_cert'], $stored->getRawOriginal('idp_x509_cert'));

        $updateResponse = $this->actingAs($owner)
            ->putJson(route('mgmt.teams.saml-provider.upsert', $team), [
                ...$payload,
                'enabled' => false,
                'sp_private_key' => '',
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.has_sp_private_key', true);

        $this->actingAs($owner)
            ->getJson(route('mgmt.teams.saml-provider.show', $team))
            ->assertOk()
            ->assertJsonPath('data.id', $stored->id);
    }

    #[Test]
    public function public_metadata_endpoint_returns_sp_metadata(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner)
            ->putJson(route('mgmt.teams.saml-provider.upsert', $team), $this->payload())
            ->assertOk();

        $response = $this->get(route('auth.saml.metadata', $team));

        $response->assertOk();
        $this->assertStringContainsString('<md:EntityDescriptor', $response->getContent());
        $this->assertStringContainsString(route('auth.saml.acs', $team), $response->getContent());
    }

    private function payload(): array
    {
        return [
            'enabled' => true,
            'idp_entity_id' => 'https://idp.example.test/metadata',
            'sso_url' => 'https://idp.example.test/sso',
            'slo_url' => 'https://idp.example.test/slo',
            'idp_x509_cert' => self::CERTIFICATE,
            'sp_x509_cert' => self::CERTIFICATE,
            'sp_private_key' => self::PRIVATE_KEY,
            'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'attribute_mapping' => [
                'email' => 'email',
                'first_name' => 'firstName',
                'last_name' => 'lastName',
                'external_id' => 'NameID',
            ],
            'role_attribute' => 'groups',
            'role_mapping' => [
                'cms-admins' => 'admin',
            ],
            'default_role' => 'member',
            'allow_jit' => true,
            'strict' => true,
            'sign_authn_requests' => true,
            'sign_logout_requests' => true,
            'want_assertions_signed' => true,
            'want_messages_signed' => false,
            'want_assertions_encrypted' => false,
            'digest_algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            'signature_algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        ];
    }
}
