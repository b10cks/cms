<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PublishAiSkill;
use App\Enums\AiScope;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Ai\Run\ContextBuilder;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

class AiSkillResolutionTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    #[Test]
    public function nearer_layers_shadow_farther_ones_and_a_disabled_skill_falls_through(): void
    {
        $user = User::factory()->create();
        $ancestor = Team::factory()->create();
        $team = Team::factory()->create(['parent_id' => $ancestor->id]);
        $space = Space::factory()->create();
        $space->forceFill(['team_id' => $team->id])->save();

        $resolve = fn () => app(SkillResolver::class)->resolve($space)['rewrite'];

        $this->assertSame(AiScope::System, $resolve()->source);

        $this->skill($ancestor, $user, 'From the ancestor team.');
        $this->assertSame([AiScope::Team, $ancestor->id], [$resolve()->source, $resolve()->ownerId]);

        $this->skill($team, $user, 'From the owning team.');
        $this->assertSame([AiScope::Team, $team->id], [$resolve()->source, $resolve()->ownerId]);

        $spaceSkill = $this->skill($space, $user, 'From the space.');
        $this->assertSame(AiScope::Space, $resolve()->source);
        $this->assertSame('From the space.', $resolve()->manifest->instructions);
        $this->assertSame(['team', 'team', 'system'], array_column($resolve()->shadows, 'source'));

        $spaceSkill->update(['enabled' => false]);
        $this->assertSame([AiScope::Team, $team->id], [$resolve()->source, $resolve()->ownerId]);
    }

    #[Test]
    public function a_space_selection_skill_is_offered_in_the_selection_menu(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $user, 'editor');
        $this->skill($space, $user, 'Make it sparkle.', 'sparkle', 'selection');
        Sanctum::actingAs($user);

        $names = $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/context?surface=selection")
            ->assertOk()
            ->json('data.*.name');

        $this->assertContains('sparkle', $names);
        $this->assertContains('rewrite', $names);
        $this->assertNotContains('meta-tags', $names);
    }

    #[Test]
    public function space_ai_settings_endpoints_resolve_the_space_from_the_route(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $user, 'admin');
        Sanctum::actingAs($user);

        $this->assertContains('rewrite', $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/skills")->assertOk()->json('resolved.*.name'));
        $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/knowledge")->assertOk();
        $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/glossaries")->assertOk();
    }

    #[Test]
    public function the_glossary_prompt_uses_only_the_requested_regions_wording(): void
    {
        $user = User::factory()->create();
        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        $glossary = AiGlossary::create([
            ...AiGlossary::ownerAttributes($space),
            'name' => 'Colors',
            'enabled' => true,
        ]);
        $this->glossaryRow($glossary, ['en' => 'color', 'de-AT' => 'Farbe AT', 'de-DE' => 'Farbe DE']);

        $built = app(ContextBuilder::class)->build(new StartRun(
            space: $space,
            actor: $user,
            surface: 'cmdk',
            prompt: 'Translate color.',
            context: ['source_locale' => 'en', 'target_locale' => 'de-DE'],
        ), toolsAvailable: true);

        $system = $built['transcript']->messages()[0]['content'];
        $this->assertStringContainsString('write "Farbe DE" in de-DE', $system);
        $this->assertStringNotContainsString('Farbe AT', $system);
    }

    private function skill(Space|Team $owner, User $user, string $body, string $name = 'rewrite', string $surface = 'selection'): AiSkill
    {
        $skill = AiSkill::create([
            ...AiSkill::ownerAttributes($owner),
            'name' => $name,
            'draft' => ['files' => ['SKILL.md' => "---\nname: {$name}\ndescription: Custom {$name}.\nb10cks:\n  surfaces: [{$surface}]\n---\n\n{$body}"]],
        ]);
        app(PublishAiSkill::class)->handle($skill, $user);

        return $skill;
    }
}
