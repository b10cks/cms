<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PublishAiSkill;
use App\Enums\AiScope;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiSkillResolutionTest extends TestCase
{
    use RefreshDatabase;

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
