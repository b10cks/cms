<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamHierarchyControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Flatten every team id present anywhere in a nested hierarchy payload.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, string>
     */
    private function collectIds(array $nodes): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            $ids[] = $node['id'];
            $ids = [...$ids, ...$this->collectIds($node['children'] ?? [])];
        }

        return $ids;
    }

    #[Test]
    public function hierarchy_is_scoped_to_the_users_accessible_teams(): void
    {
        $user = User::factory()->create();

        $accessibleRoot = Team::factory()->create();
        $accessibleChild = Team::factory()->create(['parent_id' => $accessibleRoot->id]);
        $unrelated = Team::factory()->create();
        $unrelatedChild = Team::factory()->create(['parent_id' => $unrelated->id]);

        $this->assignTeamRole($accessibleRoot, $user, 'owner');

        $response = $this->actingAs($user)->getJson(route('mgmt.teams.hierarchy'));

        $response->assertOk();
        $ids = $this->collectIds($response->json('data'));

        $this->assertContains($accessibleRoot->id, $ids);
        $this->assertContains($accessibleChild->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
        $this->assertNotContains($unrelatedChild->id, $ids);
    }

    #[Test]
    public function nested_children_are_returned_in_the_payload(): void
    {
        $user = User::factory()->create();
        $root = Team::factory()->create();
        $child = Team::factory()->create(['parent_id' => $root->id]);
        $this->assignTeamRole($root, $user, 'owner');

        $data = $this->actingAs($user)->getJson(route('mgmt.teams.hierarchy'))->json('data');

        $rootNode = collect($data)->firstWhere('id', $root->id);
        $this->assertNotNull($rootNode);
        $this->assertSame($child->id, $rootNode['children'][0]['id']);
    }

    #[Test]
    public function root_sees_the_full_hierarchy(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $ids = $this->collectIds(
            $this->actingAs($root)->getJson(route('mgmt.teams.hierarchy'))->json('data')
        );

        $this->assertContains($teamA->id, $ids);
        $this->assertContains($teamB->id, $ids);
    }

    #[Test]
    public function a_user_with_no_accessible_teams_is_forbidden(): void
    {
        $user = User::factory()->create();
        Team::factory()->create();

        $this->actingAs($user)
            ->getJson(route('mgmt.teams.hierarchy'))
            ->assertForbidden();
    }
}
