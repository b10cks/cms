<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PublishAiKnowledgeDocument;
use App\Enums\AiKnowledgeType;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiPolicySnapshot;
use App\Models\Space\AiRun;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\Space\TranslationUnit;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use App\Services\Ai\Run\RunToolFactory;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Skills\SkillResolver;
use App\Services\Ai\Tools\GetTranslationTaskTool;
use App\Services\Ai\Tools\LookupGlossaryTool;
use App\Services\Ai\Tools\ProposeTranslationTool;
use App\Services\Ai\Tools\ProposeUpdateTool;
use App\Services\Ai\Translation\TranslationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

class AiPolicySnapshotTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    private Space $space;

    private AiGlossary $glossary;

    private Block $page;

    private FakeAiDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->driver = $this->fakeAi();
        Sanctum::actingAs($this->user);
        $this->glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop', 'enabled' => true]);

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
    public function final_output_checks_use_terms_beyond_the_prompt_subset(): void
    {
        $forbidden = [];
        $rows = [];
        foreach (range(0, 500) as $index) {
            $term = "term-{$index}";
            $forbidden[$term] = "blocked-{$index}";
            $rows[] = ['languages' => ['en' => ['use' => $term, 'avoid' => [$forbidden[$term]]]]];
        }
        GlossaryConcepts::transaction($this->glossary, function (GlossaryConcepts $concepts) use ($rows) {
            foreach ($rows as $row) {
                $concepts->create($row);
            }
        });
        $run = $this->startRun();
        $this->assertCount(500, $run->setup['glossary']['concepts']);
        $frozen = array_map(static fn (array $concept) => $concept['languages']['en']['use'], $run->setup['glossary']['concepts']);
        $omitted = array_values(array_diff(array_keys($forbidden), $frozen))[0];
        $this->driver->push(FakeAiDriver::text($forbidden[$omitted]));

        foreach (app(AiRunService::class)->advance($run) as $event) {
        }

        $this->assertContains($forbidden[$omitted], array_column($run->refresh()->result['glossary_findings'], 'found'));
    }

    #[Test]
    public function non_translation_proposals_record_the_pinned_policy_and_activated_terms(): void
    {
        $row = $this->glossaryRow($this->glossary, ['en' => 'cart']);
        $source = $this->entry('en', ['title' => 'Your cart']);
        $run = $this->startRun();
        app(ProposeUpdateTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user])->execute([
            'target_type' => 'content', 'id' => $source->id, 'version_id' => $source->current_version_id,
            'changes' => [['path' => 'title', 'value' => 'Your cart is ready']],
        ]);

        $policy = AiChangesetItem::query()->sole()->review['policy'];
        $this->assertSame($run->setup['policy']['id'], $policy['snapshot_id']);
        $this->assertSame($row, $policy['terms'][0]['concept_id']);
    }

    #[Test]
    public function propose_update_writes_the_draft_and_tells_the_agent_what_to_fix_without_storing_hints(): void
    {
        $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => 'Warenkorb']);
        $this->glossaryRow($this->glossary, ['de' => ['avoid' => ['Korb', 'Korb*']]]);
        $source = $this->entry('en', ['title' => 'Your cart', 'summary' => 'Cart']);
        $run = $this->startRun(['source_locale' => 'en', 'target_locale' => 'de'], 'Your cart');
        $tool = fn () => app(ProposeUpdateTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user]);
        $call = fn (string $value) => $tool()->execute([
            'target_type' => 'content', 'id' => $source->id, 'version_id' => $source->fresh()->current_version_id,
            'changes' => [['path' => 'title', 'value' => $value]],
        ]);

        $result = $call('Ihr Korbwagen ist bereit');

        $this->assertSame('Ihr Korbwagen ist bereit', $source->fresh()->getCurrentContent()['title']);
        $this->assertSame([['type' => 'forbidden_variant', 'term' => 'Korb', 'found' => 'Korbwagen']], $result['glossary_findings']);
        $this->assertSame('missing_term', $result['glossary_hints'][0]['type']);
        $this->assertStringContainsString('once', $result['glossary_note']);
        $this->assertSame(['forbidden_variant'], array_column(AiChangesetItem::query()->sole()->glossary_findings, 'type'), 'The hint is not stored.');

        $fixed = $call('Ihr Warenkorb ist bereit');
        $this->assertArrayNotHasKey('glossary_findings', $fixed);
    }

    #[Test]
    public function a_generic_write_is_checked_in_the_language_of_its_entry_not_of_the_run(): void
    {
        $row = $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']]]);
        $entry = $this->entry('de', ['title' => 'Matratze']);
        $run = $this->startRun(prompt: 'Improve this page');
        $this->assertSame('en', $run->setup['glossary']['target_locale'], 'The run itself names no language.');
        $tools = app(RunToolFactory::class)->make($run, $this->space, $this->user);

        $read = $tools->get('get_entry')->execute(['id' => $entry->id]);
        $this->assertStringContainsString('never use "Polsterauflage"', $read['glossary']);

        $result = $tools->get('propose_update')->execute([
            'target_type' => 'content', 'id' => $entry->id, 'version_id' => $read['version_id'],
            'changes' => [['path' => 'title', 'value' => 'Die beste Polsterauflage']],
        ]);

        $finding = ['type' => 'forbidden_variant', 'term' => 'Matratze', 'expected' => 'Matratze', 'found' => 'Polsterauflage'];
        $this->assertSame([$finding], $result['glossary_findings']);
        $item = AiChangesetItem::query()->sole();
        $this->assertSame([$finding], $item->glossary_findings);
        $this->assertSame(['source' => 'de', 'target' => 'de'], $item->review['locales']);
        $this->assertSame($row, $item->review['policy']['terms'][0]['concept_id'], 'Provenance reads the entry in its language too.');
    }

    #[Test]
    public function one_run_over_entries_in_different_languages_checks_each_in_its_own_under_one_policy(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']]]);
        $this->glossaryRow($this->glossary, ['en' => ['use' => 'mattress', 'avoid' => ['cheap']]]);
        $english = $this->entry('en', ['title' => 'A mattress']);
        $german = $this->entry('de', ['title' => 'Eine Matratze']);
        $run = app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'bulk:content', prompt: 'Improve these pages', targets: ['type' => 'content', 'ids' => [$english->id, $german->id]]));
        $tools = app(RunToolFactory::class)->make($run, $this->space, $this->user);
        $found = fn (Content $entry, string $title) => array_column($tools->get('propose_update')->execute([
            'target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->fresh()->current_version_id,
            'changes' => [['path' => 'title', 'value' => $title]],
        ])['glossary_findings'] ?? [], 'found');

        $this->assertSame([], $found($german, 'Ein cheap Angebot'), 'English wording is no rule in a German entry.');
        $this->assertSame([], $found($english, 'One Polsterauflage'), 'German wording is no rule in an English entry.');
        $this->assertSame(['Polsterauflage'], $found($german, 'Eine Polsterauflage'));
        $this->assertSame(['cheap'], $found($english, 'A cheap mattress'));

        $stored = AiChangesetItem::query()->where('status', 'accepted')->get();
        $this->assertSame([$run->setup['policy']['id']], $stored->pluck('review.policy.snapshot_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['de', 'en'], $stored->pluck('review.locales.target')->unique()->values()->all());
    }

    #[Test]
    public function a_write_to_an_empty_field_need_not_hold_the_terms_of_the_run(): void
    {
        $this->glossaryRow($this->glossary, ['en' => 'b10cks'], ['keep_exact' => true]);
        $entry = $this->entry('en', ['title' => 'About b10cks']);
        $run = $this->startRun(['content_id' => $entry->id], 'Fill in the summary of the b10cks page');

        $result = app(ProposeUpdateTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user])->execute([
            'target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id,
            'changes' => [['path' => 'summary', 'value' => 'What the product does']],
        ]);

        $this->assertArrayNotHasKey('glossary_findings', $result);
    }

    #[Test]
    public function a_run_about_one_entry_writes_in_its_language_and_an_explicit_locale_still_wins(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']]]);
        $this->glossaryRow($this->glossary, ['en' => ['use' => 'mattress', 'avoid' => ['cheap']]]);
        $entry = $this->entry('de', ['title' => 'Eine Matratze']);
        $this->driver->push(FakeAiDriver::text('Die Polsterauflage'));

        $service = app(AiRunService::class);
        $run = $service->start(new StartRun($this->space, $this->user, 'selection', prompt: 'Shorten', context: ['content_id' => $entry->id, 'selection' => 'Eine Matratze']));
        foreach ($service->advance($run) as $event) {
        }

        $this->assertSame('de', $run->refresh()->setup['glossary']['target_locale']);
        $this->assertStringContainsString('never use "Polsterauflage"', collect($this->driver->transcripts[0])->firstWhere('role', 'system')['content']);
        $this->assertSame(['Polsterauflage'], array_column(AiChangesetItem::query()->sole()->glossary_findings, 'found'));
        $this->assertSame('fr', $this->startRun(['content_id' => $entry->id, 'target_locale' => 'fr'])->setup['glossary']['target_locale']);
    }

    #[Test]
    public function a_data_entry_is_checked_in_its_dimension_language_when_its_source_has_them(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['avoid' => ['Polsterauflage']]]);
        $this->glossaryRow($this->glossary, ['fr' => ['avoid' => ['matelas']]]);
        $translatable = DataSource::factory()->create([
            'dimensions' => [['key' => 'fr', 'label' => 'French'], ['key' => 'mobile', 'label' => 'Mobile']],
            'settings' => ['dimensions_translatable' => true, 'default_dimension_locale' => 'de'],
        ]);
        $plain = DataSource::factory()->create(['dimensions' => [['key' => 'mobile', 'label' => 'Mobile']]]);
        $entry = DataEntry::factory()->create(['data_source_id' => $translatable->id, 'key' => 'a', 'value' => 'Bett', 'dimensions' => []]);
        $other = DataEntry::factory()->create(['data_source_id' => $plain->id, 'key' => 'b', 'value' => 'Bett', 'dimensions' => []]);
        $tool = app(ProposeUpdateTool::class, ['run' => $this->startRun(), 'space' => $this->space, 'actor' => $this->user]);
        $found = fn (DataEntry $row, string $path, string $value) => array_column($tool->execute(['target_type' => 'data_entry', 'id' => $row->id, 'changes' => [['path' => $path, 'value' => $value]]])['glossary_findings'] ?? [], 'found');

        $this->assertSame(['Polsterauflage'], $found($entry, 'value', 'Polsterauflage'));
        $this->assertSame(['matelas'], $found($entry, 'dimensions.fr', 'matelas'));
        $this->assertSame([], $found($other, 'dimensions.mobile', 'Polsterauflage'), 'A dimension that names no language reads the run\'s.');
    }

    #[Test]
    public function every_batch_of_an_operation_keeps_the_policy_captured_at_approval(): void
    {
        config(['ai.operations.batch_size' => 1]);
        $row = $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => 'Warenkorb']);
        $style = $this->publishedDocument('voice', AiKnowledgeType::Style, 'Original voice.');
        $this->entry('en', ['title' => 'Your cart']);
        $this->entry('en', ['title' => 'Your cart again']);
        $operation = AiOperation::query()->findOrFail($this->postJson($this->url('ai/operations'), ['kind' => 'general', 'prompt' => 'Tighten.', 'scope' => ['type' => 'content', 'filters' => ['block' => 'page']]])->assertCreated()->json('data.id'));
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Tighten each title.']])->assertOk()->json('data.plan_hash');

        $pinned = app(KnowledgeResolver::class)->capturePolicy($this->space);
        $this->assertNull($operation->policy_snapshot_id);

        // Batches run inline here, so the policy changes during the first batch.
        $changed = false;
        $this->driver->push(...array_fill(0, 8, function () use ($row, $style, &$changed): TurnResult {
            if (! $changed) {
                $changed = true;
                $this->changeGlossaryRow($this->glossary, $row, ['de' => 'Einkaufswagen']);
                $style->update(['draft' => 'Changed voice.']);
                app(PublishAiKnowledgeDocument::class)->handle($style, $this->user);
            }
            $item = AiOperationItem::query()->where('status', 'running')->orderBy('position')->first();

            return $item
                ? FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $item->target_id, 'outcome' => 'unchanged']]])
                : FakeAiDriver::text('Batch done.');
        }));

        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1.0, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();
        $runs = AiRun::query()->where('operation_id', $operation->id)->get()->filter(fn (AiRun $run) => ($run->setup['operation']['phase'] ?? null) === 'batch')->sortBy(fn (AiRun $run) => $run->setup['operation']['batch'])->values();
        $this->assertCount(2, $runs);
        [$firstRun, $secondRun] = [$runs[0], $runs[1]];
        $this->assertTrue($changed);

        $this->assertSame($pinned->id, $firstRun->setup['policy']['id']);
        $this->assertSame($pinned->id, $secondRun->setup['policy']['id']);
        $this->assertSame($operation->refresh()->policy_snapshot_id, $pinned->id);

        $system = $secondRun->transcript[0]['content'];
        $this->assertStringContainsString('Original voice.', json_encode($system));
        $this->assertStringNotContainsString('Changed voice.', json_encode($system));
        $this->assertSame(array_keys($firstRun->setup['styles']), array_keys($secondRun->setup['styles']));

        $policy = app(KnowledgeResolver::class)->policy($this->space, $secondRun);
        $this->assertSame('Warenkorb', $policy->concepts[0]->languageFor('de')->use);
    }

    #[Test]
    public function a_standalone_run_stays_on_its_snapshot_and_identical_state_reuses_the_row(): void
    {
        $row = $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => 'Warenkorb']);

        $run = $this->startRun();
        $again = $this->startRun();
        $this->assertSame($run->setup['policy']['id'], $again->setup['policy']['id']);
        $this->assertSame(1, AiPolicySnapshot::query()->count());

        $this->changeGlossaryRow($this->glossary, $row, ['de' => 'Einkaufswagen']);
        $later = $this->startRun();
        $source = $this->entry('en', ['title' => 'Your cart']);

        $this->assertNotSame($run->setup['policy']['fingerprint'], $later->setup['policy']['fingerprint']);
        $this->assertStringContainsString('write "Warenkorb"', $this->task($run, $source)['glossary']);
        $this->assertStringContainsString('write "Einkaufswagen"', $this->task($later, $source)['glossary']);
    }

    #[Test]
    public function lookup_task_and_proposal_findings_read_the_same_frozen_terms(): void
    {
        $row = $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => ['use' => 'Warenkorb', 'avoid' => ['Korb']]]);
        $run = $this->startRun();
        $source = $this->entry('en', ['title' => 'Your cart']);
        $this->changeGlossaryRow($this->glossary, $row, ['de' => ['use' => 'Einkaufswagen', 'avoid' => []]]);
        $resolver = app(KnowledgeResolver::class);

        $lookup = app(RunToolFactory::class)->make($run, $this->space, $this->user)->get('lookup_glossary')->execute(['text' => 'Your cart', 'source_locale' => 'en', 'target_locale' => 'de']);
        $this->assertSame('Warenkorb', $lookup['terms'][0]['required']);
        $this->assertStringContainsString('write "Warenkorb"', $this->task($run, $source)['glossary']);

        $result = app()->make(ProposeTranslationTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user])->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'name' => 'Wagen', 'units' => ['title' => 'Ihr Einkaufswagen im Korb'],
        ]);

        $this->assertSame(['forbidden_variant'], array_values(array_unique(array_column($result['glossary_findings'], 'type'))));
        $this->assertSame(['missing_term'], array_column($result['glossary_hints'], 'type'));
        $item = AiChangesetItem::query()->sole();
        $this->assertSame(['forbidden_variant'], array_values(array_unique(array_column($item->glossary_findings, 'type'))), 'A hint is for the agent and is not stored.');
        $this->assertSame($run->setup['policy']['fingerprint'], $item->review['policy']['fingerprint']);
        $this->assertSame($row, $item->review['policy']['terms'][0]['concept_id']);
    }

    #[Test]
    public function language_selection_keeps_relevant_guides_and_prefers_the_exact_region(): void
    {
        $this->publishedDocument('guide-all', AiKnowledgeType::Style, 'Every language.');
        $this->publishedDocument('guide-de', AiKnowledgeType::Style, 'German guide.', 'de');
        $this->publishedDocument('guide-de-at', AiKnowledgeType::Style, 'Austrian guide.', 'de_AT');
        $this->publishedDocument('guide-fr', AiKnowledgeType::Style, 'French guide.', 'fr');
        $reference = $this->publishedDocument('facts-fr', AiKnowledgeType::Reference, 'French facts.', 'fr');

        $system = fn (string $target) => json_encode($this->startRun(['source_locale' => 'en', 'target_locale' => $target])->transcript[0]['content'], JSON_UNESCAPED_UNICODE);

        $german = $system('de');
        $this->assertStringContainsString('Every language.', $german);
        $this->assertStringContainsString('German guide.', $german);
        $this->assertStringNotContainsString('French guide.', $german);

        $austrian = $system('de-AT');
        $this->assertStringContainsString('Austrian guide.', $austrian);
        $this->assertStringNotContainsString('German guide.', $austrian);

        $french = $this->startRun(['source_locale' => 'en', 'target_locale' => 'fr']);
        $this->assertContains($reference->id, $french->setup['knowledge']);

        $policy = app(KnowledgeResolver::class)->capturePolicy($this->space);
        $this->assertCount(4, $policy->styles(null), 'Work across languages keeps every guide.');
    }

    #[Test]
    public function a_run_lists_its_reference_documents_and_offers_the_knowledge_tools_only_with_some(): void
    {
        $bare = $this->startRun(['source_locale' => 'en', 'target_locale' => 'de']);
        $this->assertNotContains('search_knowledge', $bare->setup['tools']);
        $this->assertNotContains('read_knowledge', $bare->setup['tools']);
        $this->assertStringNotContainsString('## Reference documents', json_encode($bare->transcript[0]['content'], JSON_UNESCAPED_UNICODE));

        $this->publishedDocument('warranty', AiKnowledgeType::Reference, 'Ten years.', 'de', 'Warranty periods per product line.');
        $this->publishedDocument('specs', AiKnowledgeType::Reference, 'Sizes.');
        $this->publishedDocument('faq-fr', AiKnowledgeType::Reference, 'Questions.', 'fr', 'French FAQ.');
        $run = $this->startRun(['source_locale' => 'en', 'target_locale' => 'de']);
        $system = json_encode($run->transcript[0]['content'], JSON_UNESCAPED_UNICODE);

        $this->assertContains('search_knowledge', $run->setup['tools']);
        $this->assertContains('read_knowledge', $run->setup['tools']);
        $this->assertStringContainsString('- warranty (warranty, de): Warranty periods per product line.', $system);
        $this->assertStringContainsString('- specs (specs): specs', $system, 'Without a description the title stands in.');
        $this->assertStringNotContainsString('faq-fr', $system);
    }

    #[Test]
    public function a_task_in_another_language_brings_the_guide_the_run_prompt_left_out_whole(): void
    {
        $german = 'German guide. '.str_repeat('Schreib kurz und klar. ', 400);
        $this->publishedDocument('guide-de', AiKnowledgeType::Style, $german, 'de');
        $this->publishedDocument('guide-fr', AiKnowledgeType::Style, 'French guide.', 'fr');
        $run = $this->startRun();
        $this->assertSame([], $run->setup['styles']);

        $task = $this->task($run, $this->entry('en', ['title' => 'Hello']));

        $this->assertSame(['guide-de'], array_column($task['style_guides'], 'title'));
        $this->assertSame($german, $task['style_guides'][0]['text']);
    }

    #[Test]
    public function also_written_as_counts_as_the_term_without_becoming_forbidden_output(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'CloudBed', 'also' => ['Cloudbett']]], ['keep_exact' => true]);
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Lattenrost', 'also' => ['Rost']], 'en' => 'slatted frame']);
        $terms = app(KnowledgeResolver::class)->capturePolicy($this->space)->concepts;
        $checker = new GlossaryChecker;

        $this->assertSame(
            [['type' => 'translated_do_not_translate', 'term' => 'CloudBed', 'expected' => 'CloudBed']],
            $checker->check('Das cloudbett ist neu.', 'The cloudbed is new.', 'de', 'en', $terms),
        );
        $this->assertSame(
            [['type' => 'missing_term', 'term' => 'Lattenrost', 'expected' => 'slatted frame']],
            $checker->check('Der Rost hält.', 'The grate holds.', 'de', 'en', $terms),
        );
        $this->assertSame([], $checker->check('Der Lattenrost hält.', 'The slatted frame holds, unlike a Rost.', 'de', 'en', $terms), 'Also written as is not forbidden output.');

        $lookup = (new LookupGlossaryTool(fn () => $terms))->execute(['text' => 'Das Cloudbett', 'source_locale' => 'de', 'target_locale' => 'en']);
        $this->assertSame('CloudBed', $lookup['terms'][0]['term']);
        $this->assertArrayNotHasKey('forbidden', $lookup['terms'][0]);
    }

    #[Test]
    public function the_prompt_gives_wording_keep_exact_and_the_note(): void
    {
        $this->glossaryRow($this->glossary, ['en' => 'Night Shift', 'de' => 'Nachtschicht'], ['keep_exact' => true]);
        $this->glossaryRow($this->glossary, ['en' => 'CloudBed'], ['keep_exact' => true]);
        $this->glossaryRow($this->glossary, ['en' => 'Pillow'], ['note' => 'Only for the Sleep line.']);

        $prompt = json_encode($this->startRun(['source_locale' => 'en', 'target_locale' => 'de'], 'Night Shift, CloudBed and Pillow')->transcript[0]['content'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('\"Night Shift\": write \"Nachtschicht\" in de', $prompt);
        $this->assertStringContainsString('\"CloudBed\": keep exactly as \"CloudBed\"', $prompt);
        $this->assertStringContainsString('\"Pillow\": translate naturally; note: Only for the Sleep line.', $prompt);
    }

    #[Test]
    public function the_prompt_tells_a_writer_about_banned_wording_its_source_never_named(): void
    {
        $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => ['use' => 'Warenkorb', 'avoid' => ['Einkaufskorb']]]);
        $this->glossaryRow($this->glossary, ['de' => ['avoid' => ['Billig*']]]);

        $prompt = json_encode($this->startRun(['source_locale' => 'en', 'target_locale' => 'de'], 'Your cart')->transcript[0]['content'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Also never write in de: \\"Billig*\\"', $prompt);
        $this->assertStringContainsString('\\"cart\\": write \\"Warenkorb\\" in de; never use \\"Einkaufskorb\\"', $prompt);
        $this->assertSame(1, substr_count($prompt, 'Einkaufskorb'), 'A row the source activated is told once.');

        $source = $this->entry('en', ['title' => 'Summer']);
        $this->assertStringContainsString('Also never write in de: "Billig*"', $this->task($this->startRun(), $source)['glossary'], 'A run across languages hears it per entry.');
    }

    #[Test]
    public function the_meta_button_checks_banned_wording_only_in_the_pages_language(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']], 'en' => 'mattress']);
        $this->glossaryRow($this->glossary, ['de' => 'CloudBed'], ['keep_exact' => true]);
        $skill = app(SkillResolver::class)->resolve($this->space)['meta-tags'];
        $start = fn (array $context) => app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'meta-tags', skill: $skill, context: $context));

        $glossary = $start(['language' => 'de', 'source_locale' => 'de', 'page' => ['name' => 'Die Matratze', 'body' => 'CloudBed']])->setup['glossary'];

        $this->assertSame(['de', 'de', ''], [$glossary['source_locale'], $glossary['target_locale'], $glossary['source']]);
        $this->assertSame(
            ['forbidden_variant'],
            array_column((new GlossaryChecker)->check($glossary['source'], 'Die Polsterauflage', 'de', 'de', GlossaryConcept::listFromFrozen($glossary['concepts'])), 'type'),
            'A short title need not hold every protected name of the page.',
        );
        $this->assertNotSame('', $start(['language' => 'de', 'source_locale' => 'de', 'fields' => ['Die Matratze']])->setup['glossary']['source'], 'Only a page context drops the source.');
    }

    #[Test]
    public function an_exact_term_shadows_its_base_and_a_sibling_region_of_a_concept_does_not_apply(): void
    {
        $this->glossaryRow($this->glossary, ['de' => 'Tasche', 'de-AT' => 'Sackerl', 'en' => 'bag']);
        $this->glossaryRow($this->glossary, ['de-DE' => 'Tüte', 'en' => 'sack']);
        $policy = app(KnowledgeResolver::class)->capturePolicy($this->space);

        $found = fn (string $text, string $locale) => array_map(fn (array $hit) => $hit['wording']->locale, $policy->activate($text, $locale));

        $this->assertSame(['de-at'], $found('Sackerl und Tüte', 'de-AT'), 'The exact language counts, and a sibling region does not apply.');
        $this->assertSame([], $found('Tasche', 'de-AT'), 'A language replaces its base whole.');
        $this->assertSame(['de'], $found('Tasche', 'de-CH'), 'Without its own, a region uses the base.');
        $this->assertSame(['de-de'], $found('Tüte', 'de-DE'));
    }

    #[Test]
    public function get_entry_lists_the_terms_its_text_activates_from_the_pinned_policy(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']], 'en' => 'mattress'], ['note' => 'Nur Serie X.']);
        $run = $this->startRun();
        $this->glossaryRow($this->glossary, ['de' => 'Bett']);
        $with = $this->entry('de', ['title' => 'Eine neue Matratze', 'text' => 'Im Bett']);
        $without = $this->entry('de', ['title' => 'Nur ein Titel']);
        $tool = app(RunToolFactory::class)->make($run, $this->space, $this->user)->get('get_entry');

        $result = $tool->execute(['id' => $with->id]);

        $this->assertStringContainsString('Terms found in this entry (de).', $result['glossary']);
        $this->assertStringContainsString('- "Matratze": never use "Polsterauflage"; note: Nur Serie X.', $result['glossary']);
        $this->assertStringNotContainsString('Bett', $result['glossary'], 'A term added after the run started is not in its policy.');
        $this->assertSame('Also never write in de: "Polsterauflage" (write "Matratze").', $tool->execute(['id' => $without->id])['glossary'], 'A writer hears the wording before using it, in the language of the entry.');
    }

    #[Test]
    public function memory_hits_the_current_glossary_invalidates_are_not_reused(): void
    {
        $row = $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => 'Warenkorb']);
        $this->unit('Your cart', 'Ihr Warenkorb', 'old');
        $this->unit('Open the cart now', 'Warenkorb jetzt öffnen', 'old');
        $memory = app(TranslationMemory::class);
        $terms = fn () => app(KnowledgeResolver::class)->livePolicy($this->space)->concepts;

        $compliant = $memory->exactUnderPolicy(['a' => 'Your cart'], 'en', 'de', $terms());
        $this->assertSame(['a' => 'Ihr Warenkorb'], $compliant['hits'], 'Human wording that complies stays.');
        $this->assertCount(2, $memory->fuzzy(['Your cart today'], 'en', 'de', terms: $terms()));

        // Only a hint: the pair stays usable when a term merely prefers other wording.
        $this->changeGlossaryRow($this->glossary, $row, ['de' => 'Einkaufswagen']);
        $this->assertSame(['a' => 'Ihr Warenkorb'], $memory->exactUnderPolicy(['a' => 'Your cart'], 'en', 'de', $terms())['hits']);
        $this->assertCount(2, $memory->fuzzy(['Your cart today'], 'en', 'de', terms: $terms()));

        $this->changeGlossaryRow($this->glossary, $row, ['de' => ['avoid' => ['Warenkorb']]]);

        $stale = $memory->exactUnderPolicy(['a' => 'Your cart'], 'en', 'de', $terms());
        $this->assertSame([], $stale['hits']);
        $this->assertSame('forbidden_variant', $stale['rejected']['a'][0]['type']);
        $this->assertSame([], $memory->fuzzy(['Your cart today'], 'en', 'de', terms: $terms()));

        // A newer pair that fails does not hide an older one that passes.
        $this->unit('Your cart', 'Ihr Einkaufswagen', 'first');
        $this->unit('Your cart', 'Ihr Warenkorb', 'second');
        $this->assertSame(['a' => 'Ihr Einkaufswagen'], $memory->exactUnderPolicy(['a' => 'Your cart'], 'en', 'de', $terms())['hits']);
    }

    #[Test]
    public function a_run_asks_the_provider_for_a_stale_exact_hit_and_records_why(): void
    {
        $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => ['use' => 'Einkaufswagen', 'avoid' => ['Warenkorb']]]);
        $this->glossaryRow($this->glossary, ['en' => 'sale']);
        $this->unit('Your cart', 'Ihr Warenkorb', 'stale');
        $this->unit('Summer sale', 'Sommerschlussverkauf', 'fine');

        $run = app(AiRunService::class)->start(new StartRun(
            $this->space,
            $this->user,
            'selection',
            skill: app(SkillResolver::class)->resolve($this->space)['translate-fields'],
            prompt: 'Translate',
            context: ['source_locale' => 'en', 'target_locale' => 'de', 'fields' => ['a' => 'Your cart', 'b' => 'Summer sale']],
        ));

        $memory = $run->setup['translation_memory'];
        $this->assertSame(['b' => 'Sommerschlussverkauf'], $memory['exact']);
        $this->assertSame('forbidden_variant', $memory['rejected']['a'][0]['type']);
        $this->assertSame(['a' => 'Your cart'], $run->input['fields']);
    }

    #[Test]
    public function runs_and_operations_from_before_snapshots_read_the_live_state_explicitly(): void
    {
        $row = $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => 'Warenkorb']);
        $run = $this->startRun();
        $old = $run->setup;
        unset($old['policy']);
        $run->forceFill(['setup' => $old])->save();
        $this->changeGlossaryRow($this->glossary, $row, ['de' => 'Einkaufswagen']);

        $policy = app(KnowledgeResolver::class)->policy($this->space, $run->fresh());
        $this->assertFalse($policy->isPinned());
        $this->assertSame('Einkaufswagen', $policy->concepts[0]->languageFor('de')->use);

        $operation = AiOperation::query()->forceCreate([
            'space_id' => $this->space->id, 'actor_id' => $this->user->id, 'kind' => 'general', 'surface' => 'operation', 'prompt' => 'x', 'scope' => [], 'counts' => [],
        ]);
        $this->assertNull($operation->fresh()->policy_snapshot_id);
        $this->assertTrue(app(KnowledgeResolver::class)->policyForStart($this->space, ['id' => $operation->id])->isPinned());

        $this->expectException(LogicException::class);
        PolicySnapshot::fromPayload(['version' => PolicySnapshot::VERSION + 1], 'x', 'y');
    }

    #[Test]
    public function fingerprints_ignore_storage_order_and_follow_the_content(): void
    {
        $row = $this->glossaryRow($this->glossary, ['en' => 'cart', 'de' => 'Warenkorb', 'fr' => 'Panier']);
        $other = $this->glossaryRow($this->glossary, ['en' => 'bag', 'de' => 'Tasche']);
        $resolver = app(KnowledgeResolver::class);
        $before = $resolver->capturePolicy($this->space);

        $this->changeGlossaryRow($this->glossary, $row, ['fr' => 'Panier', 'de' => 'Warenkorb']);
        $this->assertSame($before->fingerprint, $resolver->capturePolicy($this->space)->fingerprint);

        $this->changeGlossaryRow($this->glossary, $row, ['de' => 'Einkaufswagen']);
        GlossaryConcepts::transaction($this->glossary, fn (GlossaryConcepts $concepts) => $concepts->remove($other));
        $after = $resolver->capturePolicy($this->space);

        $this->assertNotSame($before->fingerprint, $after->fingerprint);
        $this->assertSame($before->id, AiPolicySnapshot::query()->where('fingerprint', $before->fingerprint)->value('id'), 'Older snapshots stay stored.');
    }

    #[Test]
    public function every_knowledge_document_needs_a_description_of_up_to_200_characters(): void
    {
        $payload = ['title' => 'Warranty', 'slug' => 'warranty', 'type' => 'reference'];

        $this->postJson($this->url('ai/knowledge'), $payload)->assertJsonValidationErrors('description');
        $this->postJson($this->url('ai/knowledge'), [...$payload, 'description' => str_repeat('a', 201)])->assertJsonValidationErrors('description');
        $id = $this->postJson($this->url('ai/knowledge'), [...$payload, 'description' => 'Warranty periods.'])
            ->assertCreated()->assertJsonPath('data.description', 'Warranty periods.')->json('data.id');

        $this->patchJson($this->url("ai/knowledge/{$id}"), ['description' => ''])->assertJsonValidationErrors('description');
        $this->patchJson($this->url("ai/knowledge/{$id}"), ['title' => 'Guarantee'])->assertOk()->assertJsonPath('data.description', 'Warranty periods.');
    }

    #[Test]
    public function knowledge_documents_take_an_optional_language(): void
    {
        $id = $this->postJson($this->url('ai/knowledge'), ['title' => 'Guide', 'description' => 'How we write in Austria.', 'slug' => 'guide', 'type' => 'style', 'locale' => 'de_AT', 'draft' => 'x'])
            ->assertCreated()->assertJsonPath('data.locale', 'de-at')->json('data.id');
        $this->patchJson($this->url("ai/knowledge/{$id}"), ['locale' => null])->assertOk()->assertJsonPath('data.locale', null);
        $this->postJson($this->url('ai/knowledge'), ['title' => 'Bad', 'description' => 'Bad.', 'slug' => 'bad', 'type' => 'style', 'locale' => 'not a locale'])->assertUnprocessable();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    private function publishedDocument(string $slug, AiKnowledgeType $type, string $text, ?string $locale = null, string $description = ''): AiKnowledgeDocument
    {
        $document = AiKnowledgeDocument::create([...AiKnowledgeDocument::ownerAttributes($this->space), 'slug' => $slug, 'title' => $slug, 'description' => $description, 'type' => $type, 'locale' => $locale, 'enabled' => true, 'draft' => $text]);
        app(PublishAiKnowledgeDocument::class)->handle($document, $this->user);

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function startRun(array $context = [], string $prompt = 'Translate'): AiRun
    {
        return app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'bulk:content', prompt: $prompt, context: $context));
    }

    /**
     * @return array<string, mixed>
     */
    private function task(AiRun $run, Content $source): array
    {
        return app()->make(GetTranslationTaskTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user])->execute(['id' => $source->id, 'language' => 'de']);
    }

    private function unit(string $source, string $target, string $marker): void
    {
        TranslationUnit::query()->forceCreate([
            'source_hash' => TranslationMemory::hash($source), 'source_locale' => 'en', 'target_locale' => 'de',
            'source_text' => $source, 'target_text' => $target, 'origin' => 'human',
            'content_id' => strtolower((string) Str::ulid()), 'field_path' => $marker,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function entry(string $language, array $values): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => $values['title'] ?? 'Untitled',
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
        ]);
        $content->id = strtolower((string) Str::ulid());
        $version = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => $values, 'created_by_id' => $this->user->id]);
        $content->current_version_id = $version->id;
        $content->save();

        return $content->fresh();
    }
}
