<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class SpaceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function a_non_member_cannot_reach_another_spaces_scoped_routes(): void
    {
        $ownerSpace = Space::factory()->create();
        $owner = User::factory()->create();
        $this->assignSpaceRole($ownerSpace, $owner, 'owner');
        $this->setUpSpaceTesting($ownerSpace);

        // A user with no membership in $ownerSpace.
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        // Endpoints that previously lacked their own authorization check.
        $this->getJson("/mgmt/v1/spaces/{$ownerSpace->id}/block-tags")->assertForbidden();
        $this->getJson("/mgmt/v1/spaces/{$ownerSpace->id}/asset-tags")->assertForbidden();
        $this->getJson("/mgmt/v1/spaces/{$ownerSpace->id}/ai-configs")->assertForbidden();
        $this->getJson("/mgmt/v1/spaces/{$ownerSpace->id}/ai-settings")->assertForbidden();
        $this->postJson("/mgmt/v1/spaces/{$ownerSpace->id}/assets/export", ['as' => 'csv'])->assertForbidden();
    }

    #[Test]
    public function a_member_can_reach_their_own_spaces_scoped_routes(): void
    {
        $space = Space::factory()->create();
        $owner = User::factory()->create();
        $this->assignSpaceRole($space, $owner, 'owner');
        $this->setUpSpaceTesting($space);

        Sanctum::actingAs($owner);

        $this->getJson("/mgmt/v1/spaces/{$space->id}/block-tags")->assertOk();
        $this->getJson("/mgmt/v1/spaces/{$space->id}/ai-configs")->assertOk();
    }

    #[Test]
    public function a_migration_cannot_target_a_space_the_caller_cannot_manage(): void
    {
        $source = Space::factory()->create();
        $victim = Space::factory()->create();

        // Caller is an owner of the source space only.
        $attacker = User::factory()->create();
        $this->assignSpaceRole($source, $attacker, 'owner');

        Sanctum::actingAs($attacker);

        $this->postJson("/mgmt/v1/spaces/{$source->id}/migrations", [
            'target_space_id' => $victim->id,
            'scope' => ['content' => true],
            'conflict_strategy' => 'overwrite',
        ])->assertForbidden();
    }
}
