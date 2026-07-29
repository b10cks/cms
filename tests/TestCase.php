<?php

namespace Tests;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\MembershipService;
use App\Services\Auth\RoleService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected User $user;

    /**
     * Setup markers are real files on the storage volume, and the
     * registration-closed latch in particular is written by any test that
     * exercises the gate with an account present. Redirect them into a testing
     * directory and clear them per test, so nothing leaks into the working
     * tree or between test cases. Individual tests may still point these
     * elsewhere in their own setUp().
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'setup.state_path' => storage_path('app/testing/base-state/install-state.json'),
            'setup.http_enabled_path' => storage_path('app/testing/base-state/http-enabled'),
            'setup.registration_closed_path' => storage_path('app/testing/base-state/registration-closed'),
        ]);

        $this->clearSetupMarkers();
    }

    protected function tearDown(): void
    {
        $this->clearSetupMarkers();

        parent::tearDown();
    }

    private function clearSetupMarkers(): void
    {
        foreach (['state_path', 'http_enabled_path', 'registration_closed_path'] as $key) {
            @unlink((string) config("setup.{$key}"));
        }

        @rmdir(storage_path('app/testing/base-state'));
    }

    protected function createAndActAs(?User $user = null): void
    {
        if (! $user) {
            $user = User::factory()->create();
        }
        $this->user = $user;
        $this->actingAs($this->user);
    }

    protected function assignSpaceRole(Space $space, User $user, string $roleKey): void
    {
        app(MembershipService::class)->assignSpaceRole($space, $user, $roleKey);
    }

    protected function assignTeamRole(Team $team, User $user, string $roleKey): void
    {
        app(MembershipService::class)->assignTeamRole($team, $user, $roleKey);
    }

    protected function assertSpaceRole(Space $space, User $user, string $roleKey): void
    {
        $roleId = app(RoleService::class)->resolveSpaceRole($roleKey, $space->team)->id;

        $this->assertDatabaseHas('space_user', [
            'space_id' => $space->id,
            'user_id' => $user->id,
            'role_id' => $roleId,
        ]);
    }

    protected function assertTeamRole(Team $team, User $user, string $roleKey): void
    {
        $roleId = app(RoleService::class)->resolveTeamRole($roleKey)->id;

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role_id' => $roleId,
        ]);
    }
}
