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
