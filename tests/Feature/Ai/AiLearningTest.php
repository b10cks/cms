<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\PublishContent;
use App\Enums\AiScope;
use App\Enums\AiThreadKind;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiConversation;
use App\Models\Space\AiGlossarySuggestion;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiLearningTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function editing_accepted_ai_output_yields_a_suggestion_and_a_term_already_in_the_glossary_yields_none(): void
    {
        $this->user = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $this->user, 'owner');
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);
        $this->giveSpaceAiConfig($space);
        $driver = $this->fakeAi();
        $this->actingAs($this->user);

        $glossary = AiGlossary::create(['scope' => AiScope::Space, 'space_id' => $space->id, 'name' => 'Shop', 'enabled' => true]);
        $glossary->terms()->create(['source_locale' => 'en', 'term' => 'Checkout']);
        $content = $this->publishedPage($space);

        $driver->push(
            new TurnResult('', [new ToolCall('call_1', 'propose_update', [
                'target_type' => 'content',
                'id' => $content->id,
                'version_id' => $content->current_version_id,
                'changes' => [['path' => 'title', 'value' => 'Go to the cart checkout']],
            ])], new TurnUsage(10, 5)),
            FakeAiDriver::text('Done.'),
            FakeAiDriver::text(json_encode(['terms' => [
                ['term' => 'basket', 'source_locale' => 'en', 'forbidden' => ['cart']],
                ['term' => 'checkout', 'source_locale' => 'en-GB'],
            ]])),
        );

        $runs = app(AiRunService::class);
        $run = $runs->start(new StartRun($space, $this->user, 'cmdk', prompt: 'Improve the title.'));
        iterator_to_array($runs->advance($run), false);
        $changeset = AiChangeset::query()->sole();
        $this->postJson("/mgmt/v1/spaces/{$space->id}/ai/changesets/{$changeset->id}/accept")->assertOk();

        app(PublishContent::class)->execute(['content' => ['title' => 'Go to the basket checkout']], $content->fresh(), $space, $this->user);

        $this->assertSame(3, $driver->calls);
        $this->assertStringContainsString('Go to the cart checkout', $driver->lastUserText());
        $this->assertStringContainsString('Go to the basket checkout', $driver->lastUserText());

        $suggestion = AiGlossarySuggestion::query()->sole();
        $this->assertSame(['basket', ['cart']], [$suggestion->term, $suggestion->forbidden]);
        $thread = AiConversation::query()->where('kind', AiThreadKind::Suggestions->value)->sole();
        $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/threads/{$thread->id}")
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.suggestions.0.term', 'basket')
            ->assertJsonPath('data.glossaries.0.id', $glossary->id);
    }

    #[Test]
    public function editing_an_ai_translated_field_before_publish_yields_a_suggestion(): void
    {
        [$space, $driver] = $this->setUpLearning();
        $content = $this->publishedPage($space);

        $driver->push(
            FakeAiDriver::text(json_encode(['f1' => 'Go to the cart checkout', 'f2' => 'Unaddressed'])),
            FakeAiDriver::text(json_encode(['terms' => [['term' => 'basket', 'source_locale' => 'en', 'forbidden' => ['cart']]]])),
        );

        $runs = app(AiRunService::class);
        $run = $runs->start(new StartRun(
            $space,
            $this->user,
            'translate',
            skill: app(SkillResolver::class)->resolve($space)['translate-fields'],
            context: ['source' => 'de', 'target' => 'en', 'fields' => ['f1' => 'Zur Kasse', 'f2' => 'Rich text'], 'content_id' => $content->id, 'paths' => ['f1' => 'title']],
        ));
        iterator_to_array($runs->advance($run), false);

        $item = AiChangeset::query()->sole()->items()->sole();
        $this->assertSame(['title', 'Go to the cart checkout', 'accepted'], [$item->path, $item->after, $item->status->value]);

        app(PublishContent::class)->execute(['content' => ['title' => 'Go to the basket checkout']], $content->fresh(), $space, $this->user);

        $this->assertStringContainsString('Go to the basket checkout', $driver->lastUserText());
        $this->assertSame('basket', AiGlossarySuggestion::query()->sole()->term);
    }

    #[Test]
    public function an_edited_selection_proposal_is_accepted_with_the_edited_value(): void
    {
        [$space, $driver] = $this->setUpLearning();

        $driver->push(
            FakeAiDriver::text('Go to the cart'),
            FakeAiDriver::text(json_encode(['terms' => [['term' => 'basket', 'source_locale' => 'en']]])),
        );

        $runs = app(AiRunService::class);
        $run = $runs->start(new StartRun(
            $space,
            $this->user,
            'selection',
            skill: app(SkillResolver::class)->resolve($space)['rewrite'],
            context: ['selection' => 'Go to checkout'],
        ));
        iterator_to_array($runs->advance($run), false);
        $changeset = AiChangeset::query()->sole();
        $item = $changeset->items()->sole();

        $this->postJson("/mgmt/v1/spaces/{$space->id}/ai/changesets/{$changeset->id}/accept", [
            'item_ids' => [$item->id],
            'values' => [$item->id => 'Go to the basket'],
        ])->assertOk();

        $this->assertSame(['accepted', 'Go to the basket'], [$item->fresh()->status->value, $item->fresh()->final]);
        $this->assertStringContainsString('Go to the basket', $driver->lastUserText());
        $this->assertSame('basket', AiGlossarySuggestion::query()->sole()->term);
    }

    /**
     * @return array{0: Space, 1: FakeAiDriver}
     */
    private function setUpLearning(): array
    {
        $this->user = User::factory()->create();
        $space = Space::factory()->create();
        $this->assignSpaceRole($space, $this->user, 'owner');
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);
        $this->giveSpaceAiConfig($space);
        $driver = $this->fakeAi();
        $this->actingAs($this->user);

        return [$space, $driver];
    }

    private function publishedPage(Space $space): Content
    {
        $block = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => ['title' => ['type' => 'text', 'name' => 'Title']],
        ]);

        $content = new Content;
        $content->forceFill([
            'block_id' => $block->id,
            'name' => 'Cart',
            'slug' => 'cart',
            'full_slug' => '/cart',
            'language_iso' => 'en',
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => ['title' => 'Go to checkout'],
            'created_by_id' => $this->user->id,
            'published_at' => now(),
        ]);

        $content->current_version_id = $version->id;
        $content->published_version_id = $version->id;
        $content->published_at = now();
        $content->save();

        return $content->fresh();
    }
}
