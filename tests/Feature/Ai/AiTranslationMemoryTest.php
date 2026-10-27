<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\PublishContent;
use App\Enums\AiRunState;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\TranslationUnit;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiTranslationMemoryTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private FakeAiDriver $driver;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [['code' => 'de', 'name' => 'German', 'fallback_language' => null]],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true],
            ],
        ]);
    }

    #[Test]
    public function publishing_a_translated_entry_stores_its_units_and_republishing_unchanged_text_does_not_duplicate_them(): void
    {
        $canonical = $this->entry('en', ['title' => 'Shopping cart', 'summary' => 'Pay securely']);
        $german = $this->entry('de', ['title' => 'Shopping cart', 'summary' => ''], $canonical);
        $publish = app(PublishContent::class);

        $publish->execute(['content' => ['title' => 'Warenkorb', 'summary' => 'Sicher bezahlen']], $german, $this->space, $this->user);
        $publish->execute(['content' => ['title' => 'Warenkorb', 'summary' => 'Sicher bezahlen']], $german->fresh(), $this->space, $this->user);

        $units = TranslationUnit::query()->orderBy('field_path')->get();
        $this->assertCount(2, $units);
        $this->assertSame(['Pay securely', 'Sicher bezahlen', 'en', 'de'], [$units[0]->source_text, $units[0]->target_text, $units[0]->source_locale, $units[0]->target_locale]);
        $this->assertSame(['Shopping cart', 'Warenkorb'], [$units[1]->source_text, $units[1]->target_text]);
        $this->assertSame($german->id, $units[1]->content_id);
    }

    #[Test]
    public function an_exact_translation_memory_match_fills_the_field_without_a_provider_call(): void
    {
        $canonical = $this->entry('en', ['title' => 'Shopping cart', 'summary' => 'Pay securely']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $canonical);
        app(PublishContent::class)->execute(['content' => ['title' => 'Warenkorb', 'summary' => '']], $german, $this->space, $this->user);

        $service = app(AiRunService::class);
        $run = $service->start(new StartRun(
            $this->space,
            $this->user,
            'translate',
            skill: app(SkillResolver::class)->resolve($this->space)['translate-fields'],
            context: ['source' => 'en', 'target' => 'de', 'fields' => ['hero.title' => 'Shopping  cart ']],
        ));
        iterator_to_array($service->advance($run), false);

        $run->refresh();
        $this->assertSame(0, $this->driver->calls);
        $this->assertSame(AiRunState::Completed, $run->state);
        $this->assertSame(['hero.title' => 'Warenkorb'], $run->result['data']);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function entry(string $language, array $values, ?Content $canonical = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => $values['title'] ?: 'Untitled',
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
            'i18n_parent_id' => $canonical?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $values,
            'created_by_id' => $this->user->id,
            'published_at' => $canonical ? null : now(),
        ]);

        $content->current_version_id = $version->id;
        $content->published_version_id = $canonical ? null : $version->id;
        $content->published_at = $canonical ? null : now();
        $content->save();

        return $content->fresh();
    }
}
