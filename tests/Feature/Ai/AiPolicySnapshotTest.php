<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PublishAiKnowledgeDocument;
use App\Enums\AiKnowledgeType;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\Space;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiPolicySnapshot;
use App\Models\Space\AiRun;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Knowledge\PolicySnapshot;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Tools\GetTranslationTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiPolicySnapshotTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

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
    public function every_batch_of_an_operation_keeps_the_policy_captured_at_approval(): void
    {
        config(['ai.operations.batch_size' => 1]);
        $style = $this->publishedDocument('voice', AiKnowledgeType::Style, 'Original voice.');
        $this->entry('en', ['title' => 'Your cart']);
        $this->entry('en', ['title' => 'Your cart again']);
        $operation = AiOperation::query()->findOrFail($this->postJson($this->url('ai/operations'), ['kind' => 'general', 'prompt' => 'Tighten.', 'scope' => ['type' => 'content', 'filters' => ['block' => 'page']]])->assertCreated()->json('data.id'));
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Tighten each title.']])->assertOk()->json('data.plan_hash');

        $pinned = app(KnowledgeResolver::class)->capturePolicy($this->space);
        $this->assertNull($operation->policy_snapshot_id);

        // Batches run inline here, so the policy changes during the first batch.
        $changed = false;
        $this->driver->push(...array_fill(0, 8, function () use ($style, &$changed): TurnResult {
            if (! $changed) {
                $changed = true;
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
    }

    #[Test]
    public function a_standalone_run_stays_on_its_snapshot_and_identical_state_reuses_the_row(): void
    {
        $guide = $this->publishedDocument('guide-de', AiKnowledgeType::Style, 'Original guide.', 'de');

        $run = $this->startRun();
        $again = $this->startRun();
        $this->assertSame($run->setup['policy']['id'], $again->setup['policy']['id']);
        $this->assertSame(1, AiPolicySnapshot::query()->count());

        $guide->update(['draft' => 'Changed guide.']);
        app(PublishAiKnowledgeDocument::class)->handle($guide, $this->user);
        $later = $this->startRun();
        $source = $this->entry('en', ['title' => 'Your cart']);

        $this->assertNotSame($run->setup['policy']['fingerprint'], $later->setup['policy']['fingerprint']);
        $this->assertSame('Original guide.', $this->task($run, $source)['style_guides'][0]['text']);
        $this->assertSame('Changed guide.', $this->task($later, $source)['style_guides'][0]['text']);
    }

    #[Test]
    public function runs_and_operations_from_before_snapshots_read_the_live_state_explicitly(): void
    {
        $guide = $this->publishedDocument('voice', AiKnowledgeType::Style, 'Original voice.');
        $run = $this->startRun();
        $old = $run->setup;
        unset($old['policy']);
        $run->forceFill(['setup' => $old])->save();
        $guide->update(['draft' => 'Changed voice.']);
        app(PublishAiKnowledgeDocument::class)->handle($guide, $this->user);

        $policy = app(KnowledgeResolver::class)->policy($this->space, $run->fresh());
        $this->assertFalse($policy->isPinned());
        $this->assertSame('Changed voice.', $policy->styles(null)[0]['markdown']);

        $operation = AiOperation::query()->forceCreate([
            'space_id' => $this->space->id, 'actor_id' => $this->user->id, 'kind' => 'general', 'surface' => 'operation', 'prompt' => 'x', 'scope' => [], 'counts' => [],
        ]);
        $this->assertNull($operation->fresh()->policy_snapshot_id);
        $this->assertTrue(app(KnowledgeResolver::class)->policyForStart($this->space, ['id' => $operation->id])->isPinned());

        $this->expectException(LogicException::class);
        PolicySnapshot::fromPayload(['version' => PolicySnapshot::VERSION + 1], 'x', 'y');
    }

    #[Test]
    public function a_version_2_snapshot_still_loads_and_its_concepts_are_ignored(): void
    {
        $style = ['id' => 'd1', 'slug' => 'voice', 'title' => 'Voice', 'type' => 'style', 'locale' => null, 'revision_id' => 'r1', 'revision' => 1, 'hash' => 'h', 'markdown' => 'Be brief.'];
        $stored = AiPolicySnapshot::query()->create([
            'space_id' => $this->space->id,
            'fingerprint' => 'v2',
            'version' => 2,
            'payload' => ['version' => 2, 'concepts' => [['id' => 'c1', 'languages' => ['en' => ['use' => 'cart']]]], 'documents' => [$style]],
        ]);

        $policy = app(KnowledgeResolver::class)->snapshot($stored->id);

        $this->assertTrue($policy->isPinned());
        $this->assertSame('Be brief.', $policy->styles(['en'])[0]['markdown']);
        $this->assertArrayNotHasKey('concepts', $policy->toPayload());
        $this->assertSame(3, $policy->toPayload()['version']);

        $this->expectException(LogicException::class);
        PolicySnapshot::fromPayload(['version' => 1, 'terms' => []], 'x', 'y');
    }

    #[Test]
    public function fingerprints_ignore_storage_order_and_follow_the_content(): void
    {
        $voice = $this->publishedDocument('voice', AiKnowledgeType::Style, 'Be brief.');
        $this->publishedDocument('facts', AiKnowledgeType::Reference, 'Ten years.');
        $resolver = app(KnowledgeResolver::class);
        $before = $resolver->capturePolicy($this->space);

        $documents = $before->toPayload()['documents'];
        $shuffled = array_map(fn (array $document) => array_reverse($document, true), array_reverse($documents));
        $this->assertSame($before->fingerprint, PolicySnapshot::fromParts(['documents' => $shuffled])->fingerprint);

        $voice->update(['draft' => 'Be briefer.']);
        app(PublishAiKnowledgeDocument::class)->handle($voice, $this->user);
        $after = $resolver->capturePolicy($this->space);

        $this->assertNotSame($before->fingerprint, $after->fingerprint);
        $this->assertSame($before->id, AiPolicySnapshot::query()->where('fingerprint', $before->fingerprint)->value('id'), 'Older snapshots stay stored.');
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
