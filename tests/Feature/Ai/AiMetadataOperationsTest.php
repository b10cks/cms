<?php

namespace Tests\Feature\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\Asset;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Changesets\ChangesetRecorder;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Run\OutputSchema;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Tools\ProposeUpdateTool;
use App\Services\Auth\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiMetadataOperationsTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private const UNRELATED = ['canonical' => 'https://example.test/about', 'robots' => 'noindex', 'ogImage' => ['type' => 'asset', 'id' => '01k0s6v4rsjjjgkhepj496dj5e']];

    private const FOUR_KEYS = ['title', 'description', 'ogTitle', 'ogDescription'];

    private Space $space;

    private FakeAiDriver $driver;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => ['default_language' => 'en', 'languages' => [['code' => 'de', 'name' => 'German']]]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        // UNRELATED's ogImage must name a real asset, or saving it is a validation error.
        Asset::factory()->create(['id' => '01k0s6v4rsjjjgkhepj496dj5e']);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => ['headline' => ['type' => 'text', 'name' => 'Headline'], 'seo' => ['type' => 'meta', 'name' => 'SEO']],
        ]);
    }

    #[Test]
    public function filling_keeps_stored_values_and_unrelated_keys(): void
    {
        $entry = $this->entry(['seo' => [...self::UNRELATED, 'title' => 'Written by a person']]);
        $this->driver->push(
            $this->propose($entry, ['title' => 'A generated title', 'description' => 'New description', 'ogTitle' => 'New og', 'ogDescription' => 'New og text']),
            FakeAiDriver::text('Written.'),
        );

        $operation = $this->approve(['summary' => 'Fill metadata.', 'metadata' => ['keys' => self::FOUR_KEYS]]);

        $item = AiOperationItem::query()->sole();
        $this->assertSame(AiOperationItemStatus::Written, $item->status);
        $this->assertSame(['satisfied', 'has_value'], [$item->detail['parts']['en.seo.title']['outcome'], $item->detail['parts']['en.seo.title']['reason']]);
        $this->assertSame('written', $item->detail['parts']['en.seo.description']['outcome']);
        $this->assertEqualsCanonicalizing(['satisfied' => 1, 'written' => 3], $operation->counts['coverage']['outputs']);
        $this->assertTrue($operation->counts['coverage']['complete']);

        $written = AiChangesetItem::query()->sole();
        $this->assertSame(['seo', AiChangesetItemStatus::Accepted], [$written->path, $written->status]);

        $stored = $entry->fresh()->current_version->content['seo'];
        $this->assertSame([...self::UNRELATED, 'title' => 'Written by a person', 'description' => 'New description', 'ogTitle' => 'New og', 'ogDescription' => 'New og text'], $stored);
    }

    #[Test]
    public function an_approved_overwrite_replaces_only_its_keys(): void
    {
        $entry = $this->entry(['seo' => [...self::UNRELATED, 'title' => 'Old title', 'description' => 'Old description']]);
        $this->driver->push(
            $this->propose($entry, ['title' => 'New title', 'description' => 'Refused description']),
            FakeAiDriver::text('Written.'),
        );

        $operation = $this->approve(['summary' => 'Rewrite titles.', 'metadata' => ['keys' => ['title', 'description'], 'overwrite_keys' => ['title']]]);

        $this->assertSame([...self::UNRELATED, 'title' => 'New title', 'description' => 'Old description'], AiChangesetItem::query()->sole()->after);
        $this->assertEqualsCanonicalizing(['satisfied' => 1, 'written' => 1], $operation->counts['coverage']['outputs']);
    }

    #[Test]
    public function invalid_output_is_refused_key_by_key_and_the_rest_stays_usable(): void
    {
        $entry = $this->entry(['seo' => self::UNRELATED]);
        $this->driver->push(
            $this->propose($entry, ['title' => str_repeat('t', 61), 'description' => 'Good', 'robots' => 'index', 'ogTitle' => 42]),
            function (Transcript $transcript) use ($entry) {
                $result = json_decode((string) collect($transcript->toArray())->last(fn (array $message) => $message['role'] === 'tool')['content'], true);
                $this->assertStringContainsString('at most 60', $result['metadata']['invalid']['en.seo.title']);
                $this->assertStringContainsString('not a key the approved plan writes', $result['metadata']['invalid']['en.seo.robots']);
                $this->assertStringContainsString('must be text', $result['metadata']['invalid']['en.seo.ogTitle']);

                return $this->propose($entry, 'not an object');
            },
            FakeAiDriver::text('Gave up.'),
            FakeAiDriver::text('Gave up again.'),
        );

        $operation = $this->approve(['summary' => 'Fill.', 'metadata' => ['keys' => ['title', 'description', 'ogTitle']]]);

        $item = AiOperationItem::query()->sole();
        $this->assertSame([AiOperationItemStatus::Failed, 'not_processed'], [$item->status, $item->reason]);
        $this->assertSame('invalid', $item->detail['parts']['en.seo.title']['outcome']);
        $this->assertStringContainsString('at most 60', $item->detail['parts']['en.seo.title']['note']);
        $this->assertSame([...self::UNRELATED, 'description' => 'Good'], AiChangesetItem::query()->sole()->after);
        $this->assertFalse($operation->counts['coverage']['complete']);
    }

    #[Test]
    public function a_retry_adds_the_remaining_keys_to_the_written_field_and_keeps_the_settled_ones(): void
    {
        $entry = $this->entry(['seo' => self::UNRELATED]);
        $this->driver->push(
            $this->propose($entry, ['title' => 'First title']),
            FakeAiDriver::text('One key.'),
            function (Transcript $transcript) use ($entry) {
                $this->assertStringContainsString('\\"owed\\":[\\"en.seo.description\\"]', json_encode($transcript->toArray()));

                return $this->propose($entry, ['title' => 'A retry must not replace this', 'description' => 'Second']);
            },
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'Fill.', 'metadata' => ['keys' => ['title', 'description']]]);

        // Each write is its own item, and the draft holds both keys.
        $this->assertSame([...self::UNRELATED, 'title' => 'First title', 'description' => 'Second'], $entry->fresh()->getCurrentContent()['seo']);
        $this->assertSame(0, AiChangesetItem::query()->where('status', 'conflict')->count());
        $this->assertSame(AiOperationItemStatus::Written, AiOperationItem::query()->sole()->status);
        $this->assertTrue($operation->counts['coverage']['complete']);
    }

    #[Test]
    public function a_metadata_plan_covers_nothing_else_and_entries_without_a_meta_field_show_as_skipped(): void
    {
        $entry = $this->entry(['headline' => 'Hello']);
        $plain = Block::query()->create(['external_id' => (string) Str::uuid(), 'name' => 'Plain', 'slug' => 'plain', 'type' => 'root', 'schema' => ['headline' => ['type' => 'text', 'name' => 'Headline']]]);
        $bare = $this->entry(['headline' => 'Bare'], $plain);
        $german = $this->entry(['headline' => 'Hallo'], language: 'de');
        $this->driver->push(
            FakeAiDriver::toolCall('propose_update', ['target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id, 'changes' => [['path' => 'headline', 'value' => 'Changed']]]),
            function (Transcript $transcript) {
                $this->assertStringContainsString('covers the metadata it names', (string) collect($transcript->toArray())->last(fn (array $message) => $message['role'] === 'tool')['content']);

                return FakeAiDriver::text('Nothing.');
            },
            FakeAiDriver::text('Nothing.'),
        );

        $this->approve(['summary' => 'Fill.', 'metadata' => ['keys' => ['title'], 'locales' => ['en']]]);

        $this->assertSame(0, AiChangesetItem::query()->count());
        $this->assertSame(['skipped', 'no_meta_field'], [AiOperationItem::query()->where('target_id', $bare->id)->sole()->status->value, AiOperationItem::query()->where('target_id', $bare->id)->sole()->reason]);
        $this->assertSame('locale_not_in_plan', AiOperationItem::query()->where('target_id', $german->id)->sole()->reason);
    }

    #[Test]
    public function plans_name_real_keys_fields_and_locales(): void
    {
        $this->entry(['headline' => 'Hello']);
        $id = $this->createOperation();

        foreach ([
            ['keys' => ['canonical']],
            ['keys' => []],
            ['keys' => ['title'], 'overwrite_keys' => ['description']],
            ['keys' => ['title'], 'fields' => ['headline']],
            ['keys' => ['title'], 'locales' => ['xx']],
        ] as $metadata) {
            $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'Bad.', 'metadata' => $metadata]])->assertStatus(422);
        }
    }

    #[Test]
    public function an_operation_without_a_metadata_plan_only_fills_and_a_thread_run_merges(): void
    {
        $entry = $this->entry(['seo' => [...self::UNRELATED, 'title' => 'Stored']]);
        $this->driver->push(
            $this->propose($entry, ['title' => 'Replaced?', 'description' => 'New']),
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'Meta.']);

        $this->assertSame([...self::UNRELATED, 'title' => 'Stored', 'description' => 'New'], AiChangesetItem::query()->sole()->after);
        $this->assertFalse($operation->counts['coverage']['verified']);

        $run = app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'cmdk', prompt: 'Noindex and retitle.'));
        $tool = new ProposeUpdateTool($run, $this->space, $this->user, app(ChangeTargets::class), app(ChangesetRecorder::class), app(AuthorizationService::class));
        $read = $entry->fresh()->current_version_id;
        $tool->execute(['target_type' => 'content', 'id' => $entry->id, 'version_id' => $read, 'changes' => [['path' => 'seo', 'value' => ['title' => 'Retitled', 'robots' => 'index']]]]);

        $this->assertSame([...self::UNRELATED, 'robots' => 'index', 'title' => 'Retitled', 'description' => 'New'], AiChangesetItem::query()->whereHas('changeset', fn ($query) => $query->where('run_id', $run->id))->sole()->after);

        $this->expectException(ToolError::class);
        $tool->execute(['target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->fresh()->current_version_id, 'changes' => [['path' => 'seo', 'value' => ['title' => str_repeat('t', 61)]]]]);
    }

    #[Test]
    public function the_skill_schema_holds_the_page_answer_to_its_limits(): void
    {
        $schema = json_decode((string) file_get_contents(resource_path('ai/skills/meta-tags/schema.json')), true);
        $fits = ['title' => 'T', 'description' => 'D', 'ogTitle' => 'O', 'ogDescription' => 'G'];

        $this->assertSame([], OutputSchema::validate($fits, $schema));
        $this->assertSame(['$.title must be at most 60 characters.'], OutputSchema::validate([...$fits, 'title' => str_repeat('t', 61)], $schema));
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    private function createOperation(): string
    {
        return $this->postJson($this->url('ai/operations'), ['kind' => 'general', 'prompt' => 'Write metadata.', 'scope' => ['type' => 'content']])->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $plan */
    private function approve(array $plan): AiOperation
    {
        return $this->approveOperation(AiOperation::query()->findOrFail($this->createOperation()), $plan);
    }

    /** @param  array<string, mixed>  $plan */
    private function approveOperation(AiOperation $operation, array $plan): AiOperation
    {
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => $plan])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();

        return $operation->refresh();
    }

    private function propose(Content $entry, mixed $seo): TurnResult
    {
        return FakeAiDriver::toolCall('propose_update', ['target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id, 'changes' => [['path' => 'seo', 'value' => $seo]]]);
    }

    /** @param  array<string, mixed>  $values */
    private function entry(array $values, ?Block $block = null, string $language = 'en'): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => ($block ?? $this->page)->id,
            'name' => 'Entry '.Str::random(4),
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
        ]);
        $content->id = strtolower((string) Str::ulid());
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => $values, 'created_by_id' => $this->user->id])->id;
        $content->save();

        return $content->fresh();
    }
}
