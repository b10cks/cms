<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\PublishContent;
use App\Enums\AiScope;
use App\Models\Management\AiChangeset;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossarySuggestion;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
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
        $this->getJson("/mgmt/v1/spaces/{$space->id}/ai/inbox")
            ->assertJsonPath('suggestions.0.term', 'basket')
            ->assertJsonPath('glossaries.0.id', $glossary->id);
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
