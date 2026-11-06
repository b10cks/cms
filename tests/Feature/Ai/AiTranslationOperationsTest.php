<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\UpdateContent;
use App\Enums\AiChangesetItemStatus;
use App\Enums\AiScope;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\TranslationSource;
use App\Models\Space\TranslationUnit;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Tools\FindTranslationWorkTool;
use App\Services\Ai\Tools\GetEntryTool;
use App\Services\Ai\Tools\GetTranslationTaskTool;
use App\Services\Ai\Tools\ProposeCreateTool;
use App\Services\Ai\Tools\ProposeTranslationTool;
use App\Services\Ai\Translation\TranslationLineage;
use App\Services\Ai\Translation\TranslationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

class AiTranslationOperationsTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    private Space $space;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->user = User::factory()->create();
        $this->space = Space::factory()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                    ['code' => 'de-at', 'name' => 'Austrian German', 'fallback_language' => 'de'],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => null],
                ],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->fakeAi();
        $this->actingAs($this->user);

        Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Teaser',
            'slug' => 'teaser',
            'type' => 'nestable',
            'schema' => [
                'text' => ['type' => 'text', 'name' => 'Text', 'translatable' => true],
                'style' => ['type' => 'text', 'name' => 'Style'],
            ],
        ]);
        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true],
                'seo' => ['type' => 'meta', 'name' => 'SEO', 'translatable' => true],
                'body' => ['type' => 'blocks', 'name' => 'Body'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_builtin_skill_can_discover_read_and_propose_a_translation_through_the_run_service(): void
    {
        $source = $this->entry('en', ['title' => 'About us']);
        $driver = $this->fakeAi();
        $driver->push(
            FakeAiDriver::toolCall('find_translation_work', ['ids' => [$source->id], 'languages' => ['de']]),
            FakeAiDriver::toolCall('get_translation_task', ['id' => $source->id, 'language' => 'de']),
            FakeAiDriver::toolCall('propose_translation', [
                'source_id' => $source->id,
                'language' => 'de',
                'source_version_id' => $source->current_version_id,
                'name' => 'Über uns',
                'units' => ['title' => 'Über uns'],
            ]),
            FakeAiDriver::text('Proposed one German translation.'),
        );

        $run = app(AiRunService::class)->runToCompletion(new StartRun(
            $this->space,
            $this->user,
            'bulk:content',
            skill: app(SkillResolver::class)->resolve($this->space)['find-untranslated'],
            prompt: 'Translate into German.',
            targets: ['type' => 'content', 'ids' => [$source->id]],
        ));

        $this->assertFalse($run->partial);
        $results = collect($run->transcript)->where('role', 'tool')->pluck('content');
        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertArrayNotHasKey('error', json_decode($result, true));
        }
        $item = AiChangesetItem::query()->sole();
        $this->assertSame('de', $item->after['language_iso']);
        $this->assertSame('About us', $source->fresh()->getCurrentContent()['title']);
        $this->getJson($this->url('ai/changesets/'.$item->changeset_id))
            ->assertOk()
            ->assertJsonPath('data.items.0.translation_units.0.source', 'About us')
            ->assertJsonPath('data.items.0.translation_units.0.reason', 'missing');
    }

    #[Test]
    public function a_translation_operation_settles_a_source_only_once_every_approved_locale_is_done(): void
    {
        config(['ai.operations.batch_size' => 2]);
        $about = $this->entry('en', ['title' => 'About us']);
        $this->entry('de', ['title' => 'Über uns'], $about);
        $contact = $this->entry('en', ['title' => 'Contact']);
        $propose = fn (Content $source, string $language, string $text) => FakeAiDriver::toolCall('propose_translation', [
            'source_id' => $source->id, 'language' => $language, 'source_version_id' => $source->current_version_id, 'name' => $text, 'units' => ['title' => $text],
        ]);
        $driver = $this->fakeAi();
        $driver->push(
            $propose($about, 'fr', 'À propos'),
            $propose($contact, 'de', 'Kontakt'),
            // The batch ends with the French contact page owed.
            FakeAiDriver::text('Two of three.'),
            $propose($contact, 'fr', 'Contact'),
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->postJson($this->url('ai/operations'), ['kind' => 'translation', 'prompt' => 'Translate.', 'scope' => ['type' => 'content']])
            ->assertCreated()->assertJsonPath('data.counts.total', 2)->json('data');
        $hash = $this->patchJson($this->url("ai/operations/{$operation['id']}"), ['plan' => ['summary' => 'German and French.', 'locales' => ['de', 'fr'], 'include' => ['missing', 'empty', 'stale']]])->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$operation['id']}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation['counts']['scope_hash']])->assertOk();

        $this->assertSame('completed', AiOperation::query()->sole()->state->value);
        $ledger = AiOperationItem::query()->get()->keyBy('target_id');
        // The current German page was settled in code; only French went to the model.
        $this->assertSame(['de' => ['outcome' => 'unchanged', 'reason' => 'up_to_date'], 'fr' => ['outcome' => 'written']], $ledger[$about->id]->detail['parts']);
        $this->assertSame(['written', 0], [$ledger[$about->id]->status->value, $ledger[$about->id]->attempts]);
        // One locale written did not settle the contact page: it went to a second batch for French.
        $this->assertSame(['written', 1, 2], [$ledger[$contact->id]->status->value, $ledger[$contact->id]->attempts, $ledger[$contact->id]->batch]);
        $this->assertStringContainsString('\\"done\\":{\\"de\\":\\"written\\"}', json_encode($driver->transcripts[3]));
        $items = AiChangesetItem::query()->get();
        $this->assertSame(3, $items->count());
        $this->assertSame([$ledger[$about->id]->id, $ledger[$contact->id]->id, $ledger[$contact->id]->id], $items->sortBy('id')->pluck('operation_item_id')->all());

        $germanContact = Content::query()->where('i18n_parent_id', $contact->id)->where('language_iso', 'de')->sole();
        $this->getJson($this->url("ai/operations/{$operation['id']}/review/items?".http_build_query(['target_id' => $germanContact->id])))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.translation_units.0.reason', 'missing');
    }

    #[Test]
    public function a_missing_translation_is_written_as_a_new_draft_in_the_source_family(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        $run = $this->startRun();

        $propose = fn (array $input) => $this->tool(ProposeTranslationTool::class, $run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, ...$input,
        ]);

        $result = $propose(['name' => 'Über uns', 'units' => ['title' => 'Über uns']]);
        // A second call writes to the entry the first one created.
        $propose(['version_id' => $result['version_id'], 'units' => ['summary' => 'Wir bauen Dinge']]);

        $this->assertSame(['create', 1], [$result['action'], $result['written']]);
        $item = AiChangesetItem::query()->where('operation', 'create')->sole();
        $this->assertSame(['de', $source->id, 'Über uns'], [$item->after['language_iso'], $item->after['i18n_parent_id'], $item->after['name']]);
        $this->assertSame(['title'], TranslationSource::query()->where('changeset_item_id', $item->id)->pluck('field_path')->all());
        $this->assertSame(['summary', 'title'], TranslationSource::query()->orderBy('field_path')->pluck('field_path')->all());

        $german = Content::query()->where('i18n_parent_id', $source->id)->sole();
        $this->assertSame('de', $german->language_iso);
        $this->assertSame(['Über uns', 'Wir bauen Dinge'], [$german->getCurrentContent()['title'], $german->getCurrentContent()['summary']]);
        $this->assertNull($german->published_version_id);

        $entry = $this->tool(GetEntryTool::class, $run)->execute(['id' => $source->id]);
        $this->assertSame(['de' => $german->id], $entry['i18n']['translations']);
        $this->assertSame(['de-at', 'fr'], $entry['i18n']['missing_languages']);
        $this->assertTrue($entry['fields']['title']['translatable']);
    }

    #[Test]
    public function the_locale_and_the_destination_row_are_resolved_in_code(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $source);
        $propose = fn (string $id, string $language, ?AiRun $run = null) => $this->tool(ProposeTranslationTool::class, $run ?? $this->startRun())->execute([
            'source_id' => $id,
            'language' => $language,
            'source_version_id' => $source->current_version_id,
            'version_id' => $german->current_version_id,
            'name' => 'Nom',
            'units' => ['title' => 'Titel'],
        ]);

        // Naming the translation row still resolves the source and writes to the German row.
        $propose($german->id, 'DE');
        $this->assertSame($german->id, AiChangesetItem::query()->sole()->target_id);

        $this->assertStringContainsString('default language', $this->error(fn () => $propose($source->id, 'en')));
        $this->assertStringContainsString('not a translation language', $this->error(fn () => $propose($source->id, 'es')));
        $this->assertStringContainsString('approved for de only', $this->error(fn () => $propose($source->id, 'fr', $this->startRun(['translation' => ['locales' => ['de']]]))));
    }

    #[Test]
    public function empty_fields_are_proposed_while_human_translations_of_an_unchanged_source_are_kept(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $german = $this->entry('de', ['title' => 'Unser Team', 'summary' => ''], $source);
        $run = $this->startRun();

        $task = $this->tool(GetTranslationTaskTool::class, $run)->execute(['id' => $source->id, 'language' => 'de']);
        $this->assertSame([['summary', 'empty']], array_map(fn (array $unit) => [$unit['id'], $unit['reason']], $task['units']));
        $this->assertSame(['current' => 1], $task['kept']);

        $input = ['source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'version_id' => $german->current_version_id];
        $error = $this->error(fn () => $this->tool(ProposeTranslationTool::class, $run)->execute([...$input, 'units' => ['title' => 'Über uns', 'summary' => 'Wir bauen Dinge']]));
        $this->assertStringContainsString('title: already translated', $error);
        $this->assertSame(0, AiChangesetItem::query()->count());

        $this->tool(ProposeTranslationTool::class, $run)->execute([...$input, 'units' => ['summary' => 'Wir bauen Dinge']]);
        $item = AiChangesetItem::query()->sole();
        $this->assertSame(['summary', '', 'Wir bauen Dinge'], [$item->path, $item->before, $item->after]);
    }

    #[Test]
    public function a_source_change_marks_the_translated_field_stale_with_the_text_it_was_translated_from(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $german = $this->entry('de', ['title' => 'Über uns', 'summary' => 'Wir bauen Dinge'], $source);
        Carbon::setTestNow('2026-10-01 11:00:00');
        app(UpdateContent::class)->execute(['content' => ['title' => 'About our team', 'summary' => 'We build things'], 'force' => true], $source->fresh(), $this->space, $this->user);

        $task = $this->tool(GetTranslationTaskTool::class, $this->startRun())->execute(['id' => $source->id, 'language' => 'de']);

        $this->assertSame('outdated', $task['status']);
        $this->assertCount(1, $task['units']);
        $this->assertSame(
            ['id' => 'title', 'reason' => 'stale', 'source' => 'About our team', 'previous_source' => 'About us', 'current' => 'Über uns'],
            array_intersect_key($task['units'][0], array_flip(['id', 'reason', 'source', 'previous_source', 'current'])),
        );
        $this->assertSame(['current' => 1], $task['kept']);
        $this->assertSame('history', TranslationSource::query()->where('content_id', $german->id)->where('field_path', 'title')->value('origin'));

        $run = $this->startRun();
        $this->tool(ProposeTranslationTool::class, $run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $task['source']['version_id'],
            'version_id' => $task['destination']['version_id'], 'units' => ['title' => 'Über unser Team'],
        ]);
        $item = AiChangesetItem::query()->sole();
        $this->assertSame(['title', 'Über uns', 'Über unser Team'], [$item->path, $item->before, $item->after]);
        $this->assertSame(
            [['unit_id' => 'title', 'reason' => 'stale', 'source' => 'About our team', 'previous_source' => 'About us']],
            app(TranslationLineage::class)->forItems([$item->id])[$item->id],
        );
    }

    #[Test]
    public function a_written_ai_translation_stays_current_until_its_source_changes(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        $german = $this->entry('de', ['title' => '', 'summary' => ''], $source);
        $run = $this->startRun();
        $this->tool(ProposeTranslationTool::class, $run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id,
            'version_id' => $german->current_version_id, 'units' => ['summary' => 'Wir bauen Dinge'],
        ]);
        $this->postJson($this->url('ai/changesets/'.AiChangeset::query()->sole()->id.'/accept'))->assertOk();

        $reasons = fn () => array_column($this->tool(GetTranslationTaskTool::class, $this->startRun())->execute(['id' => $source->id, 'language' => 'de'])['units'], 'reason', 'id');
        $this->assertSame(['title' => 'empty'], $reasons());

        Carbon::setTestNow('2026-10-01 12:00:00');
        app(UpdateContent::class)->execute(['content' => ['title' => 'About us', 'summary' => 'We build better things'], 'force' => true], $source->fresh(), $this->space, $this->user);

        $this->assertSame(['title' => 'empty', 'summary' => 'stale'], $reasons());
    }

    #[Test]
    public function a_source_change_older_than_a_long_run_of_translation_versions_still_marks_the_field_stale(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $german = $this->entry('de', ['title' => 'Über uns', 'summary' => 'Wir bauen'], $source);
        Carbon::setTestNow('2026-10-01 11:00:00');
        app(UpdateContent::class)->execute(['content' => ['title' => 'About our team', 'summary' => 'We build things'], 'force' => true], $source->fresh(), $this->space, $this->user);

        // 60 later versions edit only the summary, so the title keeps its streak back to 10:00.
        foreach (range(1, 60) as $minute) {
            Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00')->addMinutes($minute));
            $version = ContentVersion::query()->forceCreate(['content_id' => $german->id, 'content' => ['title' => 'Über uns', 'summary' => "Wir bauen {$minute}"], 'created_by_id' => $this->user->id]);
        }
        $german->forceFill(['current_version_id' => $version->id])->save();

        $task = $this->tool(GetTranslationTaskTool::class, $this->startRun())->execute(['id' => $source->id, 'language' => 'de']);

        $this->assertSame(
            [['id' => 'title', 'reason' => 'stale', 'previous_source' => 'About us']],
            array_map(fn (array $unit) => array_intersect_key($unit, array_flip(['id', 'reason', 'previous_source'])), $task['units']),
        );
    }

    #[Test]
    public function a_conflict_resolved_with_an_edit_keeps_the_source_it_was_translated_from(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        $german = $this->entry('de', ['title' => 'Über uns', 'summary' => ''], $source);
        $this->tool(ProposeTranslationTool::class, $this->startRun())->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id,
            'version_id' => $german->current_version_id, 'units' => ['summary' => 'Wir bauen Dinge'],
        ]);
        $item = AiChangesetItem::query()->sole();

        // The source changes while it waits as a conflict; a person edits the text when resolving it.
        $item->update(['status' => AiChangesetItemStatus::Conflict, 'current' => 'Wir bauen Dinge']);
        Carbon::setTestNow('2026-10-01 12:00:00');
        app(UpdateContent::class)->execute(['content' => ['title' => 'About us', 'summary' => 'We build better things'], 'force' => true], $source->fresh(), $this->space, $this->user);
        Carbon::setTestNow('2026-10-01 13:00:00');
        $this->postJson($this->url('ai/changesets/'.$item->changeset_id.'/accept'), ['item_ids' => [$item->id], 'overwrite' => true, 'values' => [$item->id => 'Wir bauen tolle Dinge']])->assertOk();

        $this->assertSame('Wir bauen tolle Dinge', $german->fresh()->getCurrentContent()['summary']);
        $task = $this->tool(GetTranslationTaskTool::class, $this->startRun())->execute(['id' => $source->id, 'language' => 'de']);
        $this->assertSame(
            [['id' => 'summary', 'reason' => 'stale', 'previous_source' => 'We build things']],
            array_map(fn (array $unit) => array_intersect_key($unit, array_flip(['id', 'reason', 'previous_source'])), $task['units']),
        );
        // What the item shows beside the text is unchanged.
        $this->assertSame([['unit_id' => 'summary', 'reason' => 'empty', 'source' => 'We build things', 'previous_source' => null]], app(TranslationLineage::class)->forItems([$item->id])[$item->id]);
    }

    #[Test]
    public function a_nested_field_the_translation_lacks_is_written_with_a_block_stub_that_keeps_non_translatable_values_out(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'body' => [['id' => 'b1', 'block' => 'teaser', 'text' => 'Fast', 'style' => 'wide']], 'seo' => ['title' => 'About', 'description' => 'Who we are']]);
        $german = $this->entry('de', ['title' => 'Über uns'], $source);
        $run = $this->startRun();
        $input = ['source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'version_id' => $german->current_version_id];

        $error = $this->error(fn () => $this->tool(ProposeTranslationTool::class, $run)->execute([...$input, 'units' => ['seo.title' => 'Über']]));
        $this->assertStringContainsString('translate seo.title, seo.description together', $error);

        $this->tool(ProposeTranslationTool::class, $run)->execute([...$input, 'units' => ['body.b1.text' => 'Schnell', 'seo.title' => 'Über', 'seo.description' => 'Wer wir sind']]);
        $this->assertSame(['body', 'seo'], AiChangesetItem::query()->orderBy('path')->pluck('path')->all());

        $content = $german->fresh()->getCurrentContent();
        $this->assertSame(['b1', 'teaser', 'Schnell'], [$content['body'][0]['id'], $content['body'][0]['block'], $content['body'][0]['text']]);
        $this->assertNull($content['body'][0]['style'] ?? null);
        $this->assertSame(['Über', 'Wer wir sind'], [$content['seo']['title'], $content['seo']['description']]);
        $this->assertSame('Über uns', $content['title']);
    }

    #[Test]
    public function a_translation_that_drops_a_placeholder_or_a_tag_is_refused(): void
    {
        $source = $this->entry('en', ['title' => 'Welcome', 'summary' => 'Hello {name}, read <strong>this</strong>']);

        $error = $this->error(fn () => $this->tool(ProposeTranslationTool::class, $this->startRun())->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'name' => 'Willkommen',
            'units' => ['title' => 'Willkommen', 'summary' => 'Hallo, lies das'],
        ]));

        $this->assertStringContainsString('keep the placeholders {name}', $error);
        $this->assertStringContainsString('keep the HTML tags <strong>, </strong>', $error);
        $this->assertSame(0, AiChangesetItem::query()->count());
    }

    #[Test]
    public function the_task_brings_glossary_terms_and_translation_memory_and_a_hint_goes_to_the_agent_alone(): void
    {
        $glossary = AiGlossary::create(['scope' => AiScope::Space, 'space_id' => $this->space->id, 'name' => 'Shop', 'enabled' => true]);
        $this->glossaryRow($glossary, ['en' => 'cart', 'de' => 'Warenkorb']);
        TranslationUnit::query()->forceCreate([
            'source_hash' => TranslationMemory::hash('Your cart'), 'source_locale' => 'en', 'target_locale' => 'de',
            'source_text' => 'Your cart', 'target_text' => 'Ihr Warenkorb', 'origin' => 'human',
            'content_id' => strtolower((string) Str::ulid()), 'field_path' => 'summary',
        ]);
        $source = $this->entry('en', ['title' => 'Open the cart', 'summary' => 'Your cart']);
        $run = $this->startRun();

        $task = $this->tool(GetTranslationTaskTool::class, $run)->execute(['id' => $source->id, 'language' => 'de']);
        $this->assertSame('Ihr Warenkorb', collect($task['units'])->firstWhere('id', 'summary')['memory']);
        $this->assertStringContainsString('- "cart": write "Warenkorb" in de', $task['glossary']);

        $result = $this->tool(ProposeTranslationTool::class, $run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'name' => 'Wagen',
            'units' => ['title' => 'Den Wagen öffnen', 'summary' => 'Ihr Warenkorb'],
        ]);

        $this->assertArrayNotHasKey('glossary_findings', $result);
        $this->assertSame([['type' => 'missing_term', 'term' => 'cart', 'expected' => 'Warenkorb']], $result['glossary_hints']);
        $this->assertNull(AiChangesetItem::query()->sole()->glossary_findings);
    }

    #[Test]
    public function an_empty_field_a_fallback_locale_fills_is_left_alone(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        $this->entry('de', ['title' => 'Über uns', 'summary' => 'Wir bauen Dinge'], $source);
        $this->entry('de-at', ['title' => '', 'summary' => ''], $source);

        $task = $this->tool(GetTranslationTaskTool::class, $this->startRun())->execute(['id' => $source->id, 'language' => 'de-at']);

        $this->assertSame(['current', [], ['inherited' => 2]], [$task['status'], $task['units'], $task['kept']]);
    }

    #[Test]
    public function propose_create_makes_entries_in_another_language_translations_of_a_source(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        $create = fn (array $input) => $this->tool(ProposeCreateTool::class, $this->startRun())->execute(['block' => 'page', 'name' => 'Über uns', ...$input]);

        $this->assertStringContainsString('translation_of', $this->error(fn () => $create(['language' => 'de', 'content' => ['title' => 'Über uns']])));

        $create(['translation_of' => $source->id, 'language' => 'de', 'content' => ['title' => 'Über uns']]);
        $after = AiChangesetItem::query()->sole()->after;
        $this->assertSame([$source->id, 'de', $this->page->id, ['title' => 'Über uns']], [$after['i18n_parent_id'], $after['language_iso'], $after['block_id'], $after['content']]);

        $this->entry('fr', ['title' => 'À propos'], $source);
        $this->assertStringContainsString('already has a fr translation', $this->error(fn () => $create(['translation_of' => $source->id, 'language' => 'fr', 'content' => ['title' => 'À propos']])));
    }

    #[Test]
    public function a_selection_run_translates_its_selected_entries_only(): void
    {
        $selected = $this->entry('en', ['title' => 'About us', 'summary' => 'We build things']);
        $other = $this->entry('en', ['title' => 'Contact', 'summary' => 'Write to us']);
        $run = app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'bulk:content', prompt: 'Translate', targets: ['type' => 'content', 'ids' => [$selected->id]]));
        $propose = fn (Content $source) => $this->tool(ProposeTranslationTool::class, $run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'name' => 'Seite', 'units' => ['title' => 'Seite'],
        ]);

        $this->assertStringContainsString('selected entries only', $this->error(fn () => $propose($other)));
        $this->assertSame('create', $propose($selected)['action']);
    }

    #[Test]
    public function finding_work_reports_each_entry_and_a_translation_another_run_wrote_is_read_as_it_stands(): void
    {
        $missing = $this->entry('en', ['title' => 'A missing', 'summary' => 'One']);
        $outdated = $this->entry('en', ['title' => 'B outdated', 'summary' => 'Two']);
        $this->entry('de', ['title' => 'B veraltet', 'summary' => ''], $outdated);
        $current = $this->entry('en', ['title' => 'C current', 'summary' => 'Three']);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->entry('de', ['title' => 'C aktuell', 'summary' => 'Drei'], $current);

        $first = $this->startRun();
        $this->tool(ProposeTranslationTool::class, $first)->execute([
            'source_id' => $missing->id, 'language' => 'de', 'source_version_id' => $missing->current_version_id, 'name' => 'A fehlt', 'units' => ['title' => 'A fehlt'],
        ]);

        $second = $this->startRun();
        $found = $this->tool(FindTranslationWorkTool::class, $second)->execute(['languages' => ['de']]);

        $this->assertSame(3, $found['total']);
        $this->assertSame(
            ['A missing' => 'outdated', 'B outdated' => 'outdated', 'C current' => 'current'],
            collect($found['entries'])->mapWithKeys(fn (array $entry) => [$entry['name'] => $entry['languages']['de']['status']])->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $setup  extra setup, such as an approved translation plan
     */
    private function startRun(array $setup = []): AiRun
    {
        $run = app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'bulk:content', prompt: 'Translate'));

        if ($setup !== []) {
            $run->update(['setup' => [...$run->setup, ...$setup]]);
        }

        return $run;
    }

    /**
     * @template T of AiToolInterface
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function tool(string $class, AiRun $run): AiToolInterface
    {
        return app()->make($class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user]);
    }

    private function error(callable $call): string
    {
        try {
            $call();
        } catch (ToolError $e) {
            return $e->getMessage();
        }

        $this->fail('Expected a tool error.');
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function entry(string $language, array $values, ?Content $canonical = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => ($values['title'] ?? '') ?: 'Untitled',
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
        ]);

        $content->current_version_id = $version->id;
        $content->save();

        return $content->fresh();
    }
}
