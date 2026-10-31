<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = User::factory()->create(['is_root' => true]);
    }

    #[Test]
    public function the_console_is_root_only_and_its_user_actions_need_an_elevation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('mgmt.provider.users.index'))->assertForbidden();
        $this->actingAs($user)->getJson(route('mgmt.provider.tenants'))->assertForbidden();

        $this->actingAs($this->root);
        $this->getJson(route('mgmt.provider.users.index'))->assertOk()->assertJsonPath('meta.segments.root', 1);
        $this->getJson(route('mgmt.provider.users.show', $user))->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson(route('mgmt.provider.tenants'))->assertOk();
        $this->getJson(route('mgmt.provider.usage'))->assertOk();
        $this->getJson(route('mgmt.provider.health'))->assertOk()->assertJsonStructure(['data' => ['queues', 'failed_jobs_24h', 'space_databases']]);
        $this->getJson(route('mgmt.provider.audit'))->assertOk();

        $this->postJson(route('mgmt.provider.users.suspend', $user), ['reason' => 'Spam'])->assertForbidden();
        $this->postJson(route('mgmt.provider.users.sign-out', $user))->assertForbidden();
    }

    #[Test]
    public function suspending_blocks_every_sign_in_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password-1')]);
        $token = $user->createToken('deploy script', ['*'])->plainTextToken;

        $this->actingAs($this->elevate($this->root))
            ->postJson(route('mgmt.provider.users.suspend', $user), ['reason' => 'Spam campaign'])
            ->assertOk();

        $user->refresh();
        $this->assertNotNull($user->suspended_at);
        $this->assertSame($this->root->id, $user->suspended_by);
        $this->assertSame(1, $user->session_epoch);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'root.user.suspended', 'entity_id' => $user->id]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson(route('mgmt.users.me.show'))->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => '', 'Referer' => 'http://localhost/'])
            ->postJson(route('auth.token.login'), ['email' => $user->email, 'password' => 'secret-password-1'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');
        $this->postJson(route('auth.token.login'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized();

        $this->actingAs($this->root)->postJson(route('mgmt.provider.users.restore', $user))->assertNoContent();
        $this->assertNull($user->refresh()->suspended_at);
    }

    #[Test]
    public function sign_out_everywhere_ends_existing_sessions(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password-1')]);
        $this->withHeader('Referer', 'http://localhost/');

        $this->postJson(route('auth.token.login'), ['email' => $user->email, 'password' => 'secret-password-1'])->assertOk();
        $this->freshRequest();
        $this->getJson(route('mgmt.users.me.show'))->assertOk();

        app(\App\Actions\Provider\SignOutEverywhere::class)->execute($this->root, $user);

        $this->freshRequest();
        $this->getJson(route('mgmt.users.me.show'))->assertUnauthorized();
    }

    #[Test]
    public function root_accounts_and_your_own_cannot_be_suspended(): void
    {
        $otherRoot = User::factory()->create(['is_root' => true]);
        $this->actingAs($this->elevate($this->root));

        $this->postJson(route('mgmt.provider.users.suspend', $otherRoot), ['reason' => 'No'])->assertUnprocessable();
        $this->postJson(route('mgmt.provider.users.suspend', $this->root), ['reason' => 'No'])->assertUnprocessable();
        $this->assertNull($otherRoot->refresh()->suspended_at);
    }

    #[Test]
    public function a_sole_owner_is_replaced_or_the_team_is_flagged(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $replaced = Team::factory()->create(['name' => 'Replaced']);
        $flagged = Team::factory()->create(['name' => 'Flagged']);
        $this->assignTeamRole($replaced, $owner, 'owner');
        $this->assignTeamRole($replaced, $member, 'member');
        $this->assignTeamRole($flagged, $owner, 'owner');

        $this->actingAs($this->elevate($this->root));

        $sheet = $this->getJson(route('mgmt.provider.users.show', $owner))->assertOk()->json('data.owned_teams');
        $this->assertEqualsCanonicalizing([$replaced->id, $flagged->id], array_column($sheet, 'id'));

        $this->postJson(route('mgmt.provider.users.suspend', $owner), [
            'reason' => 'Left the company',
            'replacement_owners' => [$replaced->id => $member->id],
        ])->assertOk()->assertJsonPath('data.ownerless_team_ids', [$flagged->id]);

        $this->assertTeamRole($replaced, $member, 'owner');
        $this->assertTeamRole($replaced, $owner, 'owner');

        $tenants = collect($this->getJson(route('mgmt.provider.tenants'))->json('data'))->keyBy('id');
        $this->assertContains('no_owner', $tenants[$flagged->id]['flags']);
        $this->assertNotContains('no_owner', $tenants[$replaced->id]['flags']);
    }

    #[Test]
    public function ownership_moves_to_the_new_owner_and_the_old_one_steps_down(): void
    {
        $owner = User::factory()->create();
        $successor = User::factory()->create();
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $owner, 'owner');

        $this->actingAs($this->elevate($this->root))
            ->postJson(route('mgmt.provider.users.transfer-ownership', $owner), [
                'team_id' => $team->id,
                'new_owner_id' => $successor->id,
            ])
            ->assertNoContent();

        $this->assertTeamRole($team, $successor, 'owner');
        $this->assertTeamRole($team, $owner, 'admin');
        $this->assertDatabaseHas('audit_logs', ['action' => 'root.team.ownership_transferred', 'entity_id' => $team->id]);
        $this->getJson(route('mgmt.provider.audit'))->assertOk()->assertJsonPath('data.0.action', 'root.team.ownership_transferred');
    }

    /** Guards outlive a request inside a test; in production each request resolves its session again. */
    private function freshRequest(): void
    {
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
    }
}
