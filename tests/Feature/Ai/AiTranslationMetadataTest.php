<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\UpdateContent;
use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationState;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\TranslationSource;
use App\Models\User;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Tools\GetTranslationTaskTool;
use App\Services\Ai\Tools\ProposeTranslationMetadataTool;
use App\Services\Ai\Tools\ProposeTranslationTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

class AiTranslationMetadataTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    private const UNRELATED = ['canonical' => 'https://example.test/about', 'robots' => 'noindex', 'ogImage' => ['type' => 'asset', 'id' => 'asset-1']];

    private const LINKED = ['keys' => ['title', 'description']];

    private Space $space;

    private FakeAiDriver $driver;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'en',
            'i18n_mode' => 'overlay',
            'languages' => [['code' => 'de', 'name' => 'German'], ['code' => 'fr', 'name' => 'French']],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);

        Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Teaser', 'slug' => 'teaser', 'type' => 'nestable',
            'schema' => ['text' => ['type' => 'text', 'name' => 'Text', 'translatable' => true], 'seo' => ['type' => 'meta', 'name' => 'SEO', 'translatable' => true]],
        ]);
        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true],
                'seo' => ['type' => 'meta', 'name' => 'SEO', 'translatable' => true],
                'body' => ['type' => 'blocks', 'name' => 'Body'],
            ],
        ]);
    }

    #[Test]
    public function one_task_translates_then_writes_metadata_from_the_draft_of_an_existing_and_a_new_locale(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'seo' => [...self::UNRELATED, 'title' => 'English title', 'description' => 'English description']]);
        $row = $this->entry('de', ['seo' => ['robots' => 'index', 'title' => 'Von Hand geschrieben']], $source);

        $this->driver->push(
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'de']),
            function (Transcript $transcript) use ($source) {
                $task = $this->lastResult($transcript);
                // Metadata keys are generated, so they are not translation work, and nothing can be written yet.
                $this->assertSame(['title'], array_column($task['units'], 'id'));
                $this->assertFalse($task['metadata']['ready']);

                return $this->translate($source, 'de', ['title' => 'Über uns'], versionId: $task['destination']['version_id']);
            },
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'de']),
            function (Transcript $transcript) use ($source) {
                $task = $this->lastResult($transcript);
                $this->assertTrue($task['metadata']['ready']);
                // The draft, not the source language.
                $this->assertSame('Über uns', $task['draft']['texts']['title']);
                $this->assertSame([['part' => 'de.seo.description', 'path' => 'seo', 'key' => 'description']], $task['metadata']['owed']);

                return $this->writeMetadata($source, 'de', $task['draft']['version_id'], ['seo' => ['title' => 'Replaced?', 'description' => 'Über uns kurz gesagt']]);
            },
            $this->translate($source, 'fr', ['title' => 'À propos'], name: 'À propos'),
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'fr']),
            function (Transcript $transcript) use ($source) {
                $task = $this->lastResult($transcript);
                $this->assertTrue($task['metadata']['ready']);
                $this->assertSame('À propos', $task['draft']['texts']['title']);
                $this->assertArrayNotHasKey('seo.title', $task['draft']['texts']);

                return $this->writeMetadata($source, 'fr', $task['draft']['version_id'], ['seo' => ['title' => 'À propos titre', 'description' => 'À propos résumé']]);
            },
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'Translate with metadata.', 'locales' => ['de', 'fr'], 'include' => ['missing', 'empty', 'stale'], 'metadata' => self::LINKED]);

        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertTrue($operation->counts['coverage']['complete']);
        $this->assertEquals(['satisfied' => 1, 'written' => 5], $operation->counts['coverage']['outputs']);
        $this->assertSame(AiOperationItemStatus::Written, AiOperationItem::query()->sole()->status);

        // The person's value and every unrelated key stay; only the missing key is written.
        $german = $row->fresh()->getCurrentContent();
        $this->assertSame('Über uns', $german['title']);
        $this->assertSame(['robots' => 'index', 'title' => 'Von Hand geschrieben', 'description' => 'Über uns kurz gesagt'], $german['seo']);

        // A new locale: its entry exists from the translation on, and metadata reads and writes that.
        $created = Content::query()->where('language_iso', 'fr')->sole();
        $stored = $created->getCurrentContent();
        $this->assertSame('À propos', $stored['title']);
        $this->assertSame('À propos titre', $stored['seo']['title']);
        // Unrelated keys come from the source, generated text never does.
        $this->assertSame(['canonical' => self::UNRELATED['canonical'], 'robots' => 'noindex', 'ogImage' => self::UNRELATED['ogImage'], 'title' => 'À propos titre', 'description' => 'À propos résumé'], $stored['seo']);
        $this->assertNull($created->published_at);
        $this->assertNull($created->published_version_id);
        $this->assertSame(0, AiChangesetItem::query()->whereIn('status', ['pending', 'conflict'])->count());
    }

    #[Test]
    public function translation_alone_never_completes_the_metadata_it_owes(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'seo' => ['title' => 'English']]);
        $this->driver->push(
            $this->translate($source, 'fr', ['title' => 'À propos'], name: 'À propos'),
            FakeAiDriver::text('Translated.'),
            FakeAiDriver::text('Nothing more.'),
        );

        $operation = $this->approve(['summary' => 'Translate.', 'locales' => ['fr'], 'include' => ['missing'], 'metadata' => self::LINKED]);

        $this->assertFalse($operation->counts['coverage']['complete']);
        $item = AiOperationItem::query()->sole();
        $this->assertSame('written', $item->detail['parts']['fr']['outcome']);
        $this->assertSame('unfinished', $item->detail['parts']['fr.seo.title']['outcome']);
        $this->assertSame(['written' => 1, 'unfinished' => 2], $operation->counts['coverage']['outputs']);
        $this->assertSame(AiOperationItemStatus::Failed, $item->status);
        $this->assertSame(1, AiChangesetItem::query()->count());
    }

    #[Test]
    public function the_write_is_bounded_by_order_the_version_read_lengths_and_plan(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'seo' => ['title' => 'English']]);
        $results = [];
        $keep = function (Transcript $transcript) use (&$results) {
            $results[] = $this->lastResult($transcript);
        };

        $this->driver->push(
            $this->writeMetadata($source, 'fr', 'any', ['seo' => ['title' => 'Too early']]),
            function (Transcript $transcript) use ($keep, $source) {
                $keep($transcript);

                return $this->translate($source, 'fr', ['title' => 'À propos'], name: 'À propos');
            },
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'fr']),
            function (Transcript $transcript) use ($keep, $source) {
                $keep($transcript);

                return $this->writeMetadata($source, 'fr', 'wrong', ['seo' => ['title' => 'Stale read']]);
            },
            function (Transcript $transcript) use ($keep, $source, &$results) {
                $keep($transcript);

                return $this->writeMetadata($source, 'de', $results[1]['draft']['version_id'], ['seo' => ['title' => 'Other locale']]);
            },
            function (Transcript $transcript) use ($keep, $source, &$results) {
                $keep($transcript);

                return $this->writeMetadata($source, 'fr', $results[1]['draft']['version_id'], ['seo' => ['title' => str_repeat('t', 61), 'description' => 'Fine', 'robots' => 'index'], 'body' => ['title' => 'Not metadata']]);
            },
            function (Transcript $transcript) use ($keep) {
                $keep($transcript);

                return FakeAiDriver::text('Stopped.');
            },
            FakeAiDriver::text('Nothing more.'),
        );

        $this->approve(['summary' => 'Translate.', 'locales' => ['fr'], 'include' => ['missing'], 'metadata' => self::LINKED]);

        [$early, , $stale, $locale, $mixed] = $results;
        $this->assertStringContainsString('not finished', $early['error'] ?? json_encode($early));
        $this->assertStringContainsString('not what you read', $stale['error'] ?? json_encode($stale));
        $this->assertStringContainsString('fr', json_encode($locale));
        $this->assertStringContainsString('at most 60', $mixed['metadata']['invalid']['fr.seo.title']);
        $this->assertStringContainsString('not a key the approved plan writes', $mixed['metadata']['invalid']['fr.seo.robots']);
        $this->assertArrayHasKey('fr.body', $mixed['metadata']['invalid']);

        $item = AiOperationItem::query()->sole();
        $this->assertSame('invalid', $item->detail['parts']['fr.seo.title']['outcome']);
        $this->assertSame('written', $item->detail['parts']['fr.seo.description']['outcome']);
        $this->assertSame('À propos', Content::query()->where('language_iso', 'fr')->sole()->getCurrentContent()['title']);
    }

    #[Test]
    public function metadata_written_after_a_person_edited_the_translation_reads_their_text(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'seo' => ['title' => 'English']]);
        $row = $this->entry('de', ['seo' => []], $source);
        $this->driver->push(
            $this->translate($source, 'de', ['title' => 'Über uns'], versionId: $row->current_version_id),
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'de']),
            function (Transcript $transcript) use ($source, $row) {
                $read = $this->lastResult($transcript)['draft']['version_id'];
                app(UpdateContent::class)->execute(['content' => ['title' => 'Wer wir sind', 'seo' => []], 'force' => true], $row->fresh(), $this->space, $this->user);

                return $this->writeMetadata($source, 'de', $read, ['seo' => ['title' => 'Aus dem alten Text']]);
            },
            function (Transcript $transcript) use ($source) {
                $this->assertStringContainsString('not what you read', json_encode($this->lastResult($transcript)));

                return FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'de']);
            },
            function (Transcript $transcript) use ($source) {
                $task = $this->lastResult($transcript);
                $this->assertSame('Wer wir sind', $task['draft']['texts']['title']);

                return $this->writeMetadata($source, 'de', $task['draft']['version_id'], ['seo' => ['title' => 'Wer wir sind, kurz']]);
            },
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'x', 'locales' => ['de'], 'include' => ['empty'], 'metadata' => ['keys' => ['title']]]);

        $this->assertTrue($operation->counts['coverage']['complete']);
        $this->assertSame(['title' => 'Wer wir sind', 'seo' => ['title' => 'Wer wir sind, kurz']], $row->fresh()->getCurrentContent());
    }

    #[Test]
    public function a_retry_keeps_the_translated_values_of_the_same_field(): void
    {
        $source = $this->entry('en', ['title' => 'About us', 'seo' => ['title' => 'English', 'ogTitle' => 'English og']]);
        $row = $this->entry('de', ['seo' => ['robots' => 'index']], $source);

        $this->driver->push(
            $this->translate($source, 'de', ['title' => 'Über uns', 'seo.ogTitle' => 'Deutsch og'], versionId: $row->current_version_id),
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'de']),
            fn (Transcript $transcript) => $this->writeMetadata($source, 'de', $this->lastResult($transcript)['draft']['version_id'], ['seo' => ['title' => 'Erster Titel']]),
            FakeAiDriver::text('Run ended early.'),
            fn () => $this->writeMetadata($source, 'de', $row->fresh()->current_version_id, ['seo' => ['title' => 'Rewritten?', 'description' => 'Später geschrieben']]),
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'Translate.', 'locales' => ['de'], 'include' => ['empty'], 'metadata' => self::LINKED]);

        $this->assertTrue($operation->counts['coverage']['complete']);
        $this->assertSame(2, $operation->batch_count);
        $this->assertSame(['robots' => 'index', 'ogTitle' => 'Deutsch og', 'title' => 'Erster Titel', 'description' => 'Später geschrieben'], $row->fresh()->getCurrentContent()['seo']);
        // The translated key keeps its lineage on the write that made it.
        $this->assertSame(AiChangesetItem::query()->where('target_id', $row->id)->where('path', 'seo')->orderBy('id')->first()->id, TranslationSource::query()->where('field_path', 'seo.ogTitle')->sole()->changeset_item_id);
    }

    #[Test]
    public function nested_meta_fields_are_owed_by_stable_path_and_what_has_none_is_a_limitation(): void
    {
        $teaser = ['id' => 'block-1', 'block' => 'teaser', 'text' => 'Teaser', 'seo' => ['robots' => 'noindex']];
        $entry = $this->entry('en', ['title' => 'Page', 'seo' => ['title' => 'Stored'], 'body' => [$teaser, ['block' => 'teaser', 'text' => 'No id']]]);
        $this->driver->push(
            FakeAiDriver::toolCall('propose_update', ['target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id, 'changes' => [['path' => 'body.block-1.seo', 'value' => ['title' => 'Nested title']]]]),
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approveKind('general', ['summary' => 'Fill.', 'metadata' => ['keys' => ['title']]]);

        $item = AiOperationItem::query()->sole();
        $this->assertSame(['en.seo.title', 'en.body.block-1.seo.title', 'en.body.@1.seo.title'], $item->detail['expected_parts']);
        $this->assertSame('satisfied', $item->detail['parts']['en.seo.title']['outcome']);
        $this->assertSame('written', $item->detail['parts']['en.body.block-1.seo.title']['outcome']);
        $this->assertSame(['skipped', 'no_stable_path'], [$item->detail['parts']['en.body.@1.seo.title']['outcome'], $item->detail['parts']['en.body.@1.seo.title']['reason']]);
        $this->assertFalse($operation->counts['coverage']['complete']);
        $this->assertSame(['robots' => 'noindex', 'title' => 'Nested title'], AiChangesetItem::query()->sole()->after);
    }

    #[Test]
    public function a_plan_can_pick_a_nested_field_by_block_and_refuses_one_that_does_not_exist(): void
    {
        $entry = $this->entry('en', ['seo' => [], 'body' => [['id' => 'block-1', 'block' => 'teaser', 'seo' => []]]]);
        $operation = AiOperation::query()->findOrFail($this->create('general'));

        $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Fill.', 'metadata' => ['keys' => ['title'], 'fields' => ['ghost.seo']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('plan.metadata.fields');

        $this->driver->push(FakeAiDriver::text('Nothing.'), FakeAiDriver::text('Nothing.'));
        $this->approveOperation($operation, ['summary' => 'Fill.', 'metadata' => ['keys' => ['title'], 'fields' => ['teaser.seo']]]);
        $this->assertSame(['en.body.block-1.seo.title'], AiOperationItem::query()->where('target_id', $entry->id)->sole()->detail['expected_parts']);
    }

    #[Test]
    public function the_composed_plan_validates_its_locales_and_a_plain_translation_plan_keeps_its_shape(): void
    {
        $this->entry('en', ['title' => 'About us']);
        $operation = AiOperation::query()->findOrFail($this->create('translation'));

        $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'x', 'locales' => ['de'], 'include' => ['missing'], 'metadata' => ['keys' => ['title'], 'locales' => ['fr']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('plan.metadata.locales');

        $plain = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'x', 'locales' => ['de'], 'include' => ['missing']]])->assertOk();
        $this->assertArrayNotHasKey('metadata', $plain->json('data.plan'));
    }

    #[Test]
    public function meta_a_translation_cannot_hold_or_reach_is_accounted_as_a_limitation(): void
    {
        $this->page->update(['schema' => [...$this->page->schema->toArray(), 'internal' => ['type' => 'meta', 'name' => 'Internal', 'translatable' => false]]]);
        $source = $this->entry('en', ['title' => 'About us', 'internal' => ['title' => 'Base'], 'body' => [['id' => 'block-1', 'block' => 'teaser', 'seo' => ['robots' => 'noindex']]]]);
        $this->driver->push(
            $this->translate($source, 'fr', ['title' => 'À propos'], name: 'À propos'),
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'fr']),
            function (Transcript $transcript) use ($source) {
                $task = $this->lastResult($transcript);
                $this->assertSame(['fr.seo.title'], array_column($task['metadata']['owed'], 'part'));

                return $this->writeMetadata($source, 'fr', $task['draft']['version_id'], ['seo' => ['title' => 'Titre']]);
            },
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'x', 'locales' => ['fr'], 'include' => ['missing'], 'metadata' => ['keys' => ['title']]]);

        $parts = AiOperationItem::query()->sole()->detail['parts'];
        $this->assertSame('not_stored_on_translation', $parts['fr.internal.title']['reason']);
        $this->assertSame('block_not_in_translation', $parts['fr.body.block-1.seo.title']['reason']);
        $this->assertSame('written', $parts['fr.seo.title']['outcome']);
        $this->assertFalse($operation->counts['coverage']['complete']);
    }

    #[Test]
    public function an_answer_resumes_only_the_blocked_unit_through_a_translation_batch_then_the_metadata_it_held(): void
    {
        $question = 'Which product does this summary mean?';
        $source = $this->entry('en', ['title' => 'About us', 'summary' => 'The model', 'seo' => ['title' => 'English']]);
        $row = $this->entry('de', ['seo' => ['robots' => 'index']], $source);
        $resumed = null;

        $this->driver->push(
            $this->translate($source, 'de', ['title' => 'Über uns'], versionId: $row->current_version_id),
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $source->id, 'outputs' => [
                ['part' => 'de', 'unit' => 'summary', 'outcome' => 'blocked', 'reason' => 'The model is not named.', 'question' => $question],
            ]]]]),
            FakeAiDriver::text('Finished what I could.'),
        );
        $operation = $this->approve(['summary' => 'Translate.', 'locales' => ['de'], 'include' => ['empty'], 'metadata' => self::LINKED]);

        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertFalse($operation->counts['coverage']['complete']);
        $item = AiOperationItem::query()->sole();
        $parts = $item->detail['parts'];
        $this->assertSame('written', $parts['de']['outcome']);
        $this->assertSame(['blocked', $question], [$parts['de#summary']['outcome'], $parts['de#summary']['question']]);
        $this->assertSame('awaiting_translation', $parts['de.seo.title']['reason']);
        $this->assertSame('Über uns', $this->item($row->id, 'title')->after);
        $titleItem = $this->item($row->id, 'title')->id;
        $read = $row->fresh()->current_version_id;

        $questions = $this->getJson($this->url("ai/operations/{$operation->id}/questions"))->assertOk()->assertJsonPath('summary.outputs', 1);
        $group = $questions->json('data.0');
        $this->getJson($this->url("ai/operations/{$operation->id}/questions/{$group['id']}/members"))->assertOk()->assertJsonPath('data.0.part', 'de#summary');

        $this->driver->push(
            function (Transcript $transcript) use (&$resumed, $source, $read) {
                $resumed = json_encode($transcript->toArray());

                // A resumed batch may not touch the unit it already wrote.
                return $this->translate($source, 'de', ['title' => 'Überschrieben', 'summary' => 'Das Modell Comfort'], versionId: $read);
            },
            function (Transcript $transcript) use ($source, $read) {
                $this->assertStringContainsString('Only these de units are owed', json_encode($this->lastResult($transcript)));

                return $this->translate($source, 'de', ['summary' => 'Das Modell Comfort'], versionId: $read);
            },
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'de']),
            function (Transcript $transcript) use ($source) {
                $task = $this->lastResult($transcript);
                $this->assertTrue($task['metadata']['ready']);
                $this->assertSame('Das Modell Comfort', $task['draft']['texts']['summary']);

                return $this->writeMetadata($source, 'de', $task['draft']['version_id'], ['seo' => ['title' => 'Comfort', 'description' => 'Das Modell']]);
            },
            FakeAiDriver::text('Done.'),
        );
        $this->postJson($this->url("ai/operations/{$operation->id}/questions/answers"), ['answers' => [['question_id' => $group['id'], 'answer' => 'The Comfort 200.', 'fingerprint' => $group['fingerprint']]]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'recorded');

        $operation->refresh();
        $this->assertStringContainsString('The Comfort 200.', $resumed);
        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertTrue($operation->counts['coverage']['complete']);
        $this->assertSame($titleItem, $this->item($row->id, 'title')->id);
        $this->assertSame('Über uns', $this->item($row->id, 'title')->after);
        $this->assertSame('Das Modell Comfort', $this->item($row->id, 'summary')->after);
        $this->assertEquals(['title' => 'Comfort', 'description' => 'Das Modell', 'robots' => 'index'], array_intersect_key($row->fresh()->getCurrentContent()['seo'], ['title' => 1, 'description' => 1, 'robots' => 1]));
    }

    #[Test]
    public function generated_metadata_keeps_its_locales_and_findings_under_the_pinned_policy(): void
    {
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop', 'enabled' => true]);
        $this->glossaryRow($glossary, ['fr' => 'matelas'], ['note' => 'Not for trade.']);
        $this->glossaryRow($glossary, ['fr' => ['use' => 'lit', 'avoid' => ['couchette']]]);
        $source = $this->entry('en', ['title' => 'Mattress', 'seo' => ['title' => 'English']]);
        $this->driver->push(
            $this->translate($source, 'fr', ['title' => 'Le matelas'], name: 'Matelas'),
            FakeAiDriver::toolCall(GetTranslationTaskTool::NAME, ['id' => $source->id, 'language' => 'fr']),
            fn (Transcript $transcript) => $this->writeMetadata($source, 'fr', $this->lastResult($transcript)['draft']['version_id'], ['seo' => ['title' => 'Le matelas et la couchette']]),
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'x', 'locales' => ['fr'], 'include' => ['missing'], 'metadata' => ['keys' => ['title']]]);

        $seo = $this->item(Content::query()->where('language_iso', 'fr')->sole()->id, 'seo');
        $this->assertSame(AiChangesetItemStatus::Accepted, $seo->status);
        $this->assertSame($operation->policy_snapshot_id, $seo->review['policy']['snapshot_id']);
        $this->assertSame('fr', $seo->review['locales']['target']);
        $this->assertSame('forbidden_variant', $seo->glossary_findings[0]['type']);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    private function create(string $kind): string
    {
        return $this->postJson($this->url('ai/operations'), [
            'kind' => $kind,
            'prompt' => 'Do it.',
            'scope' => ['type' => 'content'],
        ])->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $plan */
    private function approve(array $plan): AiOperation
    {
        return $this->approveKind('translation', $plan);
    }

    /** @param  array<string, mixed>  $plan */
    private function approveKind(string $kind, array $plan): AiOperation
    {
        return $this->approveOperation(AiOperation::query()->findOrFail($this->create($kind)), $plan);
    }

    /** @param  array<string, mixed>  $plan */
    private function approveOperation(AiOperation $operation, array $plan): AiOperation
    {
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => $plan])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();

        return $operation->refresh();
    }

    /** @param  array<string, string>  $units */
    private function translate(Content $source, string $language, array $units, ?string $name = null, ?string $versionId = null): TurnResult
    {
        return FakeAiDriver::toolCall(ProposeTranslationTool::NAME, array_filter([
            'source_id' => $source->id,
            'language' => $language,
            'source_version_id' => $source->current_version_id,
            'version_id' => $versionId,
            'name' => $name,
            'units' => $units,
        ], fn ($value) => $value !== null));
    }

    /** @param  array<string, array<string, mixed>>  $values */
    private function writeMetadata(Content $source, string $language, string $versionId, array $values): TurnResult
    {
        return FakeAiDriver::toolCall(ProposeTranslationMetadataTool::NAME, ['source_id' => $source->id, 'language' => $language, 'version_id' => $versionId, 'values' => $values]);
    }

    /** @return array<string, mixed> */
    private function lastResult(Transcript $transcript): array
    {
        $content = (string) collect($transcript->toArray())->last(fn (array $message) => $message['role'] === 'tool')['content'];

        return (array) (json_decode($content, true) ?? ['error' => $content]);
    }

    private function item(string $targetId, string $path): AiChangesetItem
    {
        return AiChangesetItem::query()->where('target_id', $targetId)->where('path', $path)->sole();
    }

    /** @param  array<string, mixed>  $values */
    private function entry(string $language, array $values, ?Content $source = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => 'Entry '.Str::random(4),
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
            'i18n_parent_id' => $source?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => $values, 'created_by_id' => $this->user->id])->id;
        $content->save();

        return $content->fresh();
    }
}
