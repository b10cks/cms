<?php

namespace Tests\Feature\Ai;

use App\Enums\AiScope;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiSettingsListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function inherited_lists_add_the_owning_team_layer_to_the_space_rows(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $space = Space::factory()->create();
        $space->forceFill(['team_id' => $team->id])->save();
        $this->assignSpaceRole($space, $user, 'admin');
        Sanctum::actingAs($user);

        foreach ([$space, $team] as $owner) {
            AiKnowledgeDocument::create([...AiKnowledgeDocument::ownerAttributes($owner), 'title' => $owner->name, 'slug' => 'voice', 'type' => 'style', 'draft' => '']);
            AiGlossary::create([...AiGlossary::ownerAttributes($owner), 'name' => $owner->name]);
        }

        $this->assertSame(['space'], $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/knowledge")->assertOk()->json('data.*.scope'));

        $documents = $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/knowledge?inherited=1")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([['space', $space->id], ['team', $team->id]], array_map(fn (array $row) => [$row['scope'], $row['owner_id']], $documents));

        $glossaries = $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/glossaries?inherited=1")->assertOk()->json('data.*.scope');
        $this->assertEqualsCanonicalizing(['space', 'team'], $glossaries);
    }

    #[Test]
    public function customizing_a_built_in_skill_creates_an_editable_space_draft(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $user, 'admin');
        Sanctum::actingAs($user);

        $this->postJson("/mgmt/v1/spaces/{$space->id}/ai/skills/customize", ['name' => 'rewrite'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'rewrite')
            ->assertJsonPath('data.scope', 'space')
            ->assertJsonPath('data.published_revision', null);

        $skill = AiSkill::query()->sole();
        $this->assertSame(AiScope::Space, $skill->scope);
        $this->assertStringContainsString('name: rewrite', $skill->draft['files']['SKILL.md']);

        $this->postJson("/mgmt/v1/spaces/{$space->id}/ai/skills/customize", ['name' => 'rewrite'])->assertUnprocessable();
        $this->postJson("/mgmt/v1/spaces/{$space->id}/ai/skills/customize", ['name' => 'no-such-skill'])->assertUnprocessable();
    }
}
