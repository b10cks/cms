<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\RoleService;
use App\Services\Team\Attention\Checks\SamlCertificateExpiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The team workspace endpoints: overview, move preview, activity and the
 * access matrix.
 */
class TeamWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_overview_counts_the_subtree_and_lists_what_needs_attention(): void
    {
        $owner = User::factory()->create();
        $agency = Team::factory()->create();
        $client = Team::factory()->create(['parent_id' => $agency->id, 'name' => 'Client']);
        $this->assignTeamRole($agency, $owner, 'owner');

        $space = Space::factory()->create(['team_id' => $client->id]);
        $editor = User::factory()->create();
        $this->assignSpaceRole($space, $editor, 'editor');
        DB::table('space_user')->where('user_id', $editor->id)->update(['expires_at' => now()->addDays(3)]);

        Invite::factory()->create([
            'space_id' => null,
            'team_id' => $agency->id,
            'email' => 'waiting@example.com',
            'created_at' => now()->subDays(9),
        ]);

        $data = $this->actingAs($owner)
            ->getJson(route('mgmt.teams.overview', $agency))
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['kpis']['members']['total']);
        $this->assertSame(1, $data['kpis']['spaces']['total']);
        $this->assertSame(['total' => 1, 'stale' => 1], $data['kpis']['pending_invites']);
        $this->assertSame(1, $data['kpis']['expiring_access']['total']);

        $this->assertSame([$agency->id, $client->id], array_column($data['structure'], 'id'));
        $this->assertNull($data['structure'][0]['parent_id']);
        $this->assertSame($space->id, $data['structure'][1]['spaces'][0]['id']);
        $this->assertTrue($data['structure'][1]['can_move']);

        $attention = collect($data['attention'])->keyBy('type');
        $this->assertSame(['waiting@example.com'], $attention['stale_invites']['details']);
        $this->assertSame(1, $attention['expiring_access']['count']);
        $this->assertSame(['Client'], $attention['teams_without_owner']['details']);
    }

    #[Test]
    public function the_overview_is_closed_to_outsiders(): void
    {
        $team = Team::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson(route('mgmt.teams.overview', $team))
            ->assertForbidden();
    }

    #[Test]
    public function a_saml_certificate_expiry_is_read_from_a_bare_base64_body(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'idp'], $key), null, $key, 10);
        openssl_x509_export($certificate, $pem);
        $body = trim(preg_replace('/-----[A-Z ]+-----/', '', $pem));

        $expiresAt = SamlCertificateExpiry::certificateExpiry($body);

        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $expiresAt->timestamp, 60);
        $this->assertNull(SamlCertificateExpiry::certificateExpiry('not a certificate'));
    }

    #[Test]
    public function the_move_preview_lists_who_gains_and_loses_access_and_which_roles_change(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $from = Team::factory()->create(['name' => 'From']);
        $to = Team::factory()->create(['name' => 'To']);
        $moved = Team::factory()->create(['parent_id' => $from->id]);
        $space = Space::factory()->create(['team_id' => $moved->id]);

        $leaving = User::factory()->create();
        $arriving = User::factory()->create();
        $this->assignTeamRole($from, $leaving, 'admin');
        $this->assignTeamRole($to, $arriving, 'member');

        $oldRole = app(RoleService::class)->createCustomSpaceRole($from, [
            'key' => 'reviewer', 'name' => 'Reviewer', 'level' => 120, 'abilities' => ['space.view'],
        ]);
        app(RoleService::class)->createCustomSpaceRole($to, [
            'key' => 'translator', 'name' => 'Translator', 'level' => 120, 'abilities' => ['space.view'],
        ]);
        $this->assignSpaceRole($space, User::factory()->create(), $oldRole->key);

        $data = $this->actingAs($root)
            ->getJson(route('mgmt.teams.move-preview', [$moved, 'parent_id' => $to->id]))
            ->assertOk()
            ->json('data');

        $this->assertSame([$arriving->id], array_column($data['gains'], 'id'));
        $this->assertSame([$leaving->id], array_column($data['losses'], 'id'));
        $this->assertSame(['translator'], array_column($data['roles_starting'], 'key'));
        $this->assertSame('reviewer', $data['roles_stopping'][0]['key']);
        $this->assertSame(1, $data['roles_stopping'][0]['assignments']);
        $this->assertSame(1, $data['direct_space_roles']);
    }

    #[Test]
    public function the_move_preview_follows_the_move_rules(): void
    {
        $owner = User::factory()->create();
        $parent = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $parent->id]);
        $this->assignTeamRole($parent, $owner, 'owner');

        $this->actingAs($owner)
            ->getJson(route('mgmt.teams.move-preview', [$child, 'parent_id' => null]))
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson(route('mgmt.teams.move-preview', [$parent, 'parent_id' => $child->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->actingAs($owner)
            ->getJson(route('mgmt.teams.move-preview', [$child, 'parent_id' => Team::factory()->create()->id]))
            ->assertForbidden();
    }

    #[Test]
    public function the_activity_lists_team_and_space_membership_changes(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($owner);
        $member = User::factory()->create(['firstname' => 'Kai', 'lastname' => 'Vogel']);
        $this->assignSpaceRole($space, $member, 'editor');

        $entries = $this->getJson(route('mgmt.teams.audit-logs.index', $team))
            ->assertOk()
            ->json('data');

        $spaceEntry = collect($entries)->firstWhere('subject.type', 'space');
        $this->assertSame('member_added', $spaceEntry['action']);
        $this->assertSame('Kai Vogel', $spaceEntry['member']['name']);
        $this->assertSame(['role' => 'editor'], $spaceEntry['new_values']);
        $this->assertTrue(collect($entries)->contains('subject.type', 'team'));

        $viewer = User::factory()->create();
        $this->assignTeamRole($team, $viewer, 'member');
        $this->actingAs($viewer)
            ->getJson(route('mgmt.teams.audit-logs.index', $team))
            ->assertForbidden();
    }

    #[Test]
    public function the_access_matrix_marks_inherited_and_direct_cells_and_who_may_edit_them(): void
    {
        $admin = User::factory()->create();
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create(['team_id' => $team->id]);
        $this->assignTeamRole($team, $admin, 'admin');
        $this->assignTeamRole($team, $owner, 'owner');
        $this->assignSpaceRole($space, $admin, 'admin');
        $this->assignSpaceRole($space, $owner, 'owner');
        $editor = User::factory()->create();
        $this->assignSpaceRole($space, $editor, 'editor');

        $data = $this->actingAs($admin)
            ->getJson(route('mgmt.teams.access-matrix', $team))
            ->assertOk()
            ->json('data');

        $people = collect($data['people'])->keyBy('id');
        $ownerCell = $people[$owner->id]['cells'][$space->id];
        $editorCell = $people[$editor->id]['cells'][$space->id];

        $this->assertSame('owner', $ownerCell['inherited']['key']);
        $this->assertSame('owner', $ownerCell['direct']['key']);
        $this->assertFalse($ownerCell['editable']);
        $this->assertSame('outranks', $ownerCell['reason']);

        $this->assertNull($editorCell['inherited']);
        $this->assertSame('editor', $editorCell['direct']['key']);
        $this->assertTrue($editorCell['editable']);
        $this->assertNotContains('owner', array_column($data['spaces'][0]['assignable_roles'], 'key'));
    }

    #[Test]
    public function a_direct_space_role_can_be_added_for_people_of_the_organisation_only(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $team->id]);
        $space = Space::factory()->create(['team_id' => $child->id]);
        $this->assignTeamRole($team, $owner, 'owner');
        $colleague = User::factory()->create();
        $this->assignTeamRole($child, $colleague, 'member');

        $this->actingAs($owner)
            ->postJson(route('mgmt.spaces.members.store', $space), ['user_id' => $colleague->id, 'role' => 'editor'])
            ->assertNoContent();
        $this->assertSpaceRole($space, $colleague, 'editor');

        $this->actingAs($owner)
            ->postJson(route('mgmt.spaces.members.store', $space), ['user_id' => User::factory()->create()->id, 'role' => 'editor'])
            ->assertUnprocessable();
    }
}
