<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\PublishContent;
use App\Actions\Content\UpdateContent;
use App\Enums\AiRunState;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\TranslationUnit;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Tools\ProposeTranslationTool;
use App\Services\Ai\Translation\TranslationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->at('09:00');
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    #[Test]
    public function republishing_an_unchanged_translation_after_the_source_moved_on_keeps_the_pair_it_was_written_against(): void
    {
        [$canonical, $german] = $this->publishedPair('Shopping cart', 'Warenkorb');
        $this->publishSource($canonical, 'Your basket');

        $this->at('12:00');
        $this->publish($german, 'Warenkorb');

        $this->assertSame(['Shopping cart' => 'Warenkorb'], $this->pairs());
        $memory = app(TranslationMemory::class);
        $this->assertSame([], $memory->exact(['title' => 'Your basket'], 'en', 'de'));
        $this->assertSame(['title' => 'Warenkorb'], $memory->exact(['title' => 'Shopping cart'], 'en', 'de'));
        // Memory is per exact locale: no region or base language stands in.
        $this->assertSame([], $memory->exact(['title' => 'Shopping cart'], 'en', 'de-at'));
    }

    #[Test]
    public function a_translation_published_after_the_source_changed_records_the_new_pair(): void
    {
        [$canonical, $german] = $this->publishedPair('Shopping cart', 'Warenkorb');
        $this->publishSource($canonical, 'Your basket');

        $this->at('12:00');
        $this->publish($german, 'Ihr Warenkorb');

        $this->assertSame(['Your basket' => 'Ihr Warenkorb'], $this->pairs());
        $this->assertSame(TranslationUnit::ORIGIN_HUMAN, TranslationUnit::query()->sole()->origin);
    }

    #[Test]
    public function recording_uses_the_published_target_and_not_a_newer_draft(): void
    {
        [$canonical, $german] = $this->publishedPair('Shopping cart', 'Warenkorb');
        $this->publishSource($canonical, 'Your basket');

        $this->at('12:00');
        $this->saveDraft($german, 'Ihr Warenkorb');

        app(TranslationMemory::class)->record($german->fresh());

        $this->assertSame(['Shopping cart' => 'Warenkorb'], $this->pairs());
    }

    #[Test]
    public function a_draft_written_before_the_source_changed_keeps_that_source_when_published_later(): void
    {
        $canonical = $this->entry('en', ['title' => 'Shopping cart', 'summary' => '']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $canonical);

        $this->at('10:00');
        $this->saveDraft($german, 'Warenkorb');
        $this->publishSource($canonical, 'Your basket');

        $this->at('12:00');
        $this->publish($german, 'Warenkorb');

        $this->assertSame(['Shopping cart' => 'Warenkorb'], $this->pairs());
    }

    #[Test]
    public function a_translation_of_the_source_draft_pairs_with_that_draft_and_not_the_published_source(): void
    {
        $canonical = $this->entry('en', ['title' => 'Shopping cart', 'summary' => '']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $canonical);

        $this->at('10:00');
        $this->saveDraft($canonical, 'Your basket');

        $this->at('11:00');
        $this->publish($german, 'Ihr Warenkorb');

        $this->assertSame(['Your basket' => 'Ihr Warenkorb'], $this->pairs());
    }

    #[Test]
    public function applied_but_unpublished_ai_text_is_never_served_as_a_memory_hit_and_published_text_is(): void
    {
        $canonical = $this->entry('en', ['title' => 'Shopping cart', 'summary' => 'Pay securely']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $canonical);
        $memory = app(TranslationMemory::class);
        $this->propose($canonical, $german, ['title' => 'Warenkorb', 'summary' => 'Sicher bezahlen']);

        // In the draft, and nothing of it is memory yet: not as a hit, not as an example.
        $this->assertSame('Warenkorb', $german->fresh()->getCurrentContent()['title']);
        $this->assertSame([], $this->pairs());
        $this->assertSame([], $memory->exact(['title' => 'Shopping cart'], 'en', 'de'));
        $this->assertSame(['hits' => [], 'rejected' => []], $memory->exactUnderPolicy(['title' => 'Shopping cart'], 'en', 'de', []));
        $this->assertSame([], $memory->fuzzy(['A shopping cart'], 'en', 'de'));
        $this->artisan('ai:backfill-translation-memory', ['space_id' => $this->space->id])->assertSuccessful();
        $this->assertSame([], $this->pairs());

        $this->at('12:00');
        $current = $german->fresh();
        app(PublishContent::class)->execute(['content' => $current->getCurrentContent()], $current, $this->space, $this->user);

        $this->assertSame(['title' => 'Warenkorb'], $memory->exact(['title' => 'Shopping cart'], 'en', 'de'));
        $this->assertSame(['Pay securely' => 'Sicher bezahlen', 'Shopping cart' => 'Warenkorb'], $this->pairs());
        $this->assertSame([TranslationUnit::ORIGIN_AI_ACCEPTED], TranslationUnit::query()->pluck('origin')->unique()->all());
    }

    #[Test]
    public function an_ai_draft_a_person_edited_before_publishing_teaches_their_text(): void
    {
        $canonical = $this->entry('en', ['title' => 'Shopping cart', 'summary' => '']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $canonical);
        $this->propose($canonical, $german, ['title' => 'Warenkorb']);

        $this->at('12:00');
        $this->publish($german->fresh(), 'Ihr Warenkorb');

        $this->assertSame(['Shopping cart' => 'Ihr Warenkorb'], $this->pairs());
        $this->assertSame(TranslationUnit::ORIGIN_HUMAN, TranslationUnit::query()->sole()->origin);
    }

    #[Test]
    public function a_new_ai_draft_after_a_publish_leaves_the_memory_as_it_was(): void
    {
        [$canonical, $german] = $this->publishedPair('Shopping cart', 'Warenkorb');
        $this->publishSource($canonical, 'Your basket');

        $this->at('12:00');
        $this->propose($canonical, $german, ['title' => 'KI-Warenkorb']);

        $this->assertSame('KI-Warenkorb', $german->fresh()->getCurrentContent()['title']);
        $this->assertSame(['Shopping cart' => 'Warenkorb'], $this->pairs());
        $this->assertSame([], app(TranslationMemory::class)->exact(['title' => 'Your basket'], 'en', 'de'));
    }

    #[Test]
    public function a_new_translation_entry_is_recorded_as_ai_text_once_published(): void
    {
        $canonical = $this->entry('en', ['title' => 'Shopping cart', 'summary' => '']);
        $this->tool(ProposeTranslationTool::class)->execute([
            'source_id' => $canonical->id, 'language' => 'de', 'source_version_id' => $canonical->current_version_id,
            'name' => 'Warenkorb', 'units' => ['title' => 'Warenkorb'],
        ]);
        $this->assertSame([], $this->pairs());

        $this->at('12:00');
        $german = Content::query()->where('i18n_parent_id', $canonical->id)->sole();
        app(PublishContent::class)->execute(['content' => $german->getCurrentContent()], $german, $this->space, $this->user);

        $this->assertSame(['Shopping cart' => 'Warenkorb'], $this->pairs());
        $this->assertSame(TranslationUnit::ORIGIN_AI_ACCEPTED, TranslationUnit::query()->sole()->origin);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function undecidedOrRejected(): array
    {
        return ['conflict' => ['conflict'], 'rejected' => ['rejected']];
    }

    #[Test]
    #[DataProvider('undecidedOrRejected')]
    public function a_change_that_was_not_written_does_not_verify_an_identical_published_value(string $decision): void
    {
        [$canonical, $german] = $this->publishedPair('Shopping cart', 'Warenkorb');
        $this->publishSource($canonical, 'Your basket');

        // A change for the new source with the text the translation already holds, that never reached the draft.
        $this->propose($canonical, $german, ['title' => 'Warenkorb']);
        AiChangesetItem::query()->sole()->update(['status' => $decision]);

        $this->at('12:00');
        $this->publish($german, 'Warenkorb');

        $this->assertSame(['Shopping cart' => 'Warenkorb'], $this->pairs());
    }

    #[Test]
    public function the_backfill_replaces_a_stale_pair_stored_by_earlier_recording(): void
    {
        [$canonical, $german] = $this->publishedPair('Shopping cart', 'Warenkorb');
        $this->publishSource($canonical, 'Your basket');
        TranslationUnit::query()->where('content_id', $german->id)->update([
            'source_text' => 'Your basket',
            'source_hash' => TranslationMemory::hash('Your basket'),
        ]);

        $this->artisan('ai:backfill-translation-memory', ['space_id' => $this->space->id])->assertSuccessful();

        $this->assertSame(['Shopping cart' => 'Warenkorb'], $this->pairs());
    }

    /**
     * A canonical entry and its German translation, published at 09:00 and 10:00.
     *
     * @return array{0: Content, 1: Content}
     */
    private function publishedPair(string $source, string $target): array
    {
        $canonical = $this->entry('en', ['title' => $source, 'summary' => '']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $canonical);

        $this->at('10:00');
        $this->publish($german, $target);

        return [$canonical, $german];
    }

    private function publishSource(Content $canonical, string $title): void
    {
        $this->at('11:00');
        $this->publish($canonical, $title);
    }

    private function publish(Content $content, string $title, string $summary = ''): void
    {
        app(PublishContent::class)->execute(['content' => ['title' => $title, 'summary' => $summary]], $content->fresh(), $this->space, $this->user);
    }

    private function saveDraft(Content $content, string $title): void
    {
        app(UpdateContent::class)->execute(['content' => ['title' => $title, 'summary' => ''], 'force' => true], $content->fresh(), $this->space, $this->user);
    }

    /**
     * @param  array<string, string>  $units
     */
    private function propose(Content $canonical, Content $german, array $units): void
    {
        $this->tool(ProposeTranslationTool::class)->execute([
            'source_id' => $canonical->id, 'language' => 'de', 'source_version_id' => $canonical->fresh()->current_version_id,
            'version_id' => $german->fresh()->current_version_id, 'units' => $units,
        ]);
    }

    /**
     * @template T of AiToolInterface
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function tool(string $class): AiToolInterface
    {
        $run = app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'bulk:content', prompt: 'Translate'));

        return app()->make($class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user]);
    }

    /**
     * @return array<string, string> source text => target text
     */
    private function pairs(): array
    {
        return TranslationUnit::query()->orderBy('field_path')->pluck('target_text', 'source_text')->all();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    private function at(string $time): void
    {
        Carbon::setTestNow("2026-10-01 {$time}:00");
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
