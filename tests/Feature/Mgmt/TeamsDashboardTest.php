<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teams start page: one card per reachable top team, summing its subtree.
 */
class TeamsDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_gets_one_card_per_top_team_with_subtree_totals(): void
    {
        $owner = User::factory()->create();
        $agency = Team::factory()->create(['name' => 'Agency']);
        $client = Team::factory()->create(['parent_id' => $agency->id, 'name' => 'Client']);
        Team::factory()->create(['name' => 'Elsewhere']);
        $this->assignTeamRole($agency, $owner, 'owner');

        $editor = User::factory()->create();
        $this->assignTeamRole($client, $editor, 'member');
        Space::factory()->create(['team_id' => $agency->id]);
        Space::factory()->create(['team_id' => $client->id]);

        $data = $this->actingAs($owner)
            ->getJson(route('mgmt.teams.dashboard'))
            ->assertOk()
            ->json('data');

        $this->assertSame(['Agency'], array_column($data['teams'], 'name'));
        $card = $data['teams'][0];
        $this->assertSame(2, $card['people_count']);
        $this->assertSame(2, $card['spaces_count']);
        $this->assertSame(1, $card['sub_team_count']);
        $this->assertSame(1, $card['teams_without_owner']);
        $this->assertSame('Client', $card['children'][0]['name']);
        $this->assertSame(2, $data['summary']['teams']);
        $this->assertCount(12, $data['summary']['teams_series']);
    }

    #[Test]
    public function root_sees_every_top_team(): void
    {
        $root = User::factory()->create(['is_root' => true]);
        Team::factory()->create(['name' => 'One']);
        Team::factory()->create(['name' => 'Two']);

        $this->actingAs($root)
            ->getJson(route('mgmt.teams.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.summary.teams', 2)
            ->assertJsonCount(2, 'data.teams');
    }
}
