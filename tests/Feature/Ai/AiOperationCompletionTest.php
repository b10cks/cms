<?php

namespace Tests\Feature\Ai;

use App\Enums\AiOperationItemStatus;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\Asset;
use App\Models\Space\AssetTag;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Operations\OutputCoverage;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use App\Services\Ai\Run\Transcript;
use App\Services\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiOperationCompletionTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private const OUTPUTS = ['fields' => ['alt', 'title'], 'tags' => true];

    private const ALL_PARTS = ['_default.alt', '_default.title', 'de.alt', 'de.title', 'tags'];

    private Space $space;

    private Storage $storage;

    private FakeAiDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'en',
            'languages' => [['code' => 'de', 'name' => 'German']],
            'asset_fields' => [
                ['key' => 'title', 'label' => 'Title', 'required' => false],
                ['key' => 'alt', 'label' => 'Alt', 'required' => false],
            ],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => ['root' => storage_path("app/spaces/{$this->space->id}")],
            'driver' => 'local',
            'state' => 'live',
        ]);
        LaravelStorage::fake($this->storage->id);
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path("app/spaces/{$this->space->id}"));

        parent::tearDown();
    }

    #[Test]
    public function approval_freezes_outputs_for_entries_beyond_the_first_batch(): void
    {
        Queue::fake();
        config(['ai.operations.batch_size' => 1]);
        $this->asset();
        $this->asset();

        $operation = $this->approve(['summary' => 'Fill metadata.', 'outputs' => self::OUTPUTS]);
        $pending = AiOperationItem::query()->where('operation_id', $operation->id)->where('status', AiOperationItemStatus::Pending)->sole();

        $this->assertSame(self::ALL_PARTS, $pending->detail['expected_parts']);
        $this->getJson($this->url("ai/operations/{$operation->id}/items"))
            ->assertOk()
            ->assertJsonPath('data.1.expected_parts', self::ALL_PARTS)
            ->assertJsonPath('data.1.units', []);
    }

    #[Test]
    public function tags_alone_settle_nothing_else_and_a_retry_owes_only_the_remaining_fields(): void
    {
        $asset = $this->storedAsset();
        $tag = AssetTag::query()->create(['name' => 'Shoes']);
        $midway = null;

        $this->driver->push(
            FakeAiDriver::toolCall('inspect_assets', ['ids' => [$asset->id]]),
            FakeAiDriver::toolCall('propose_asset_changes', ['assets' => [
                ['id' => $asset->id, 'group' => 'Shots', 'reason' => 'A shoe.', 'add_tags' => [$tag->id], 'fields' => ['_default' => ['alt' => 'First alt']]],
            ]]),
            FakeAiDriver::text('Tags and one field.'),
            function (Transcript $transcript) use ($asset, &$midway) {
                $midway = AiOperationItem::query()->sole()->replicate();
                $this->assertStringContainsString('\\"done\\":{\\"_default.alt\\":\\"proposed\\",\\"tags\\":\\"proposed\\"}', json_encode($transcript->toArray()));
                $this->assertStringContainsString('\\"owed\\":[\\"_default.title\\",\\"de.alt\\",\\"de.title\\"]', json_encode($transcript->toArray()));

                return FakeAiDriver::toolCall('inspect_assets', ['ids' => [$asset->id]]);
            },
            FakeAiDriver::toolCall('propose_asset_changes', ['assets' => [
                ['id' => $asset->id, 'group' => 'Shots', 'reason' => 'A shoe.', 'fields' => [
                    '_default' => ['alt' => 'Retry must not replace this', 'title' => 'Title'],
                    'de' => ['alt' => 'Ein Schuh', 'title' => 'Titel'],
                ]],
            ]]),
            FakeAiDriver::text('The rest.'),
        );

        $operation = $this->approve(['summary' => 'Metadata and tags.', 'outputs' => self::OUTPUTS]);

        // Back in the queue as a second batch: tags and one field did not settle the asset.
        $this->assertSame([AiOperationItemStatus::Running, 2, 1], [$midway->status, $midway->batch, $midway->attempts]);
        $item = AiOperationItem::query()->sole();
        $this->assertSame(AiOperationItemStatus::Proposed, $item->status);
        $this->assertSame(1, $item->attempts);
        $this->assertEqualsCanonicalizing(self::ALL_PARTS, $item->detail['expected_parts']);
        $this->assertSame(['proposed'], array_values(array_unique(array_column($item->detail['parts'], 'outcome'))));
        $this->assertSame('First alt', AiChangesetItem::query()->where('path', 'fields._default.alt')->sole()->after);
        $this->assertSame(5, AiChangesetItem::query()->count());

        $this->assertTrue($operation->counts['coverage']['verified']);
        $this->assertTrue($operation->counts['coverage']['complete']);
        $this->assertSame(['proposed' => 5], $operation->counts['coverage']['outputs']);
        $this->getJson($this->url("ai/operations/{$operation->id}"))->assertJsonPath('data.counts.coverage.complete', true);
    }

    #[Test]
    public function attempts_running_out_keep_the_usable_part_and_say_what_is_unfinished(): void
    {
        $asset = $this->storedAsset();
        $tag = AssetTag::query()->create(['name' => 'Shoes']);

        $this->driver->push(
            FakeAiDriver::toolCall('inspect_assets', ['ids' => [$asset->id]]),
            FakeAiDriver::toolCall('propose_asset_changes', ['assets' => [['id' => $asset->id, 'group' => 'Shots', 'reason' => 'A shoe.', 'add_tags' => [$tag->id]]]]),
            FakeAiDriver::text('Only tags.'),
            FakeAiDriver::text('Nothing more.'),
        );

        $operation = $this->approve(['summary' => 'Metadata and tags.', 'outputs' => self::OUTPUTS]);

        $item = AiOperationItem::query()->sole();
        $this->assertSame([AiOperationItemStatus::Failed, 'not_processed'], [$item->status, $item->reason]);
        $this->assertSame('proposed', $item->detail['parts']['tags']['outcome']);
        $this->assertSame(['outcome' => 'unfinished', 'reason' => 'not_processed'], $item->detail['parts']['de.title']);
        $this->assertSame(1, AiChangesetItem::query()->where('path', 'tags')->count());
        $this->assertFalse($operation->counts['coverage']['complete']);
        $this->assertEqualsCanonicalizing(['proposed' => 1, 'unfinished' => 4], $operation->counts['coverage']['outputs']);
    }

    #[Test]
    public function reported_exceptions_keep_their_reasons_without_settling_independent_fields(): void
    {
        $asset = $this->storedAsset();
        $this->driver->push(
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $asset->id, 'outputs' => [
                ['part' => '_default.alt', 'outcome' => 'intentionally_empty', 'reason' => 'Decorative image.'],
                ['part' => 'de.alt', 'outcome' => 'uninferable', 'reason' => 'Text in the image is unreadable.'],
                ['part' => '_default.title', 'outcome' => 'refused', 'reason' => 'Shows a person without consent.'],
                ['part' => 'de.title', 'outcome' => 'blocked', 'reason' => 'Product name unknown.', 'question' => 'What is the German product name?'],
                ['part' => 'tags', 'outcome' => 'satisfied', 'reason' => 'It has none, and this claim must fail.'],
                ['part' => 'not.a.part', 'outcome' => 'uninferable', 'reason' => 'No such output.'],
            ]]]]),
            function (Transcript $transcript) use ($asset) {
                $result = json_decode((string) collect($transcript->toArray())->last(fn (array $message) => $message['role'] === 'tool')['content'], true);
                $this->assertSame(4, $result['recorded']);
                $this->assertStringContainsString('holds no value to keep', $result['errors'][0]);
                $this->assertStringContainsString('is not a part this entry owes', $result['errors'][1]);
                $this->assertStringContainsString('still owes tags', $result['errors'][2]);

                return FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $asset->id, 'outputs' => [['part' => 'tags', 'outcome' => 'uninferable', 'reason' => 'No tag fits.']]]]]);
            },
            FakeAiDriver::text('Reported.'),
        );

        $operation = $this->approve(['summary' => 'Metadata and tags.', 'outputs' => self::OUTPUTS]);

        $item = AiOperationItem::query()->sole();
        $this->assertSame(AiOperationItemStatus::Skipped, $item->status);
        $this->assertSame(['outcome' => 'intentionally_empty', 'reason' => 'agent', 'note' => 'Decorative image.', 'batch' => 1], $item->detail['parts']['_default.alt']);
        $this->assertSame('Shows a person without consent.', $item->detail['parts']['_default.title']['note']);
        $this->assertSame(0, AiChangesetItem::query()->count());
        $this->assertFalse($operation->counts['coverage']['complete']);
        $this->assertSame(1, $operation->counts['coverage']['outputs']['blocked']);

        $blocked = OutputCoverage::blocked($operation);
        $this->assertSame([['item_id' => $item->id, 'target_type' => 'asset', 'target_id' => $asset->id, 'part' => 'de.title', 'question' => 'What is the German product name?', 'note' => 'Product name unknown.']], $blocked);

        $item->reopenBlocked(['de.title', '_default.alt']);
        $item->refresh();
        $this->assertSame([AiOperationItemStatus::Pending, ['de.title']], [$item->status, $item->openParts(null)]);
        $this->assertSame('intentionally_empty', $item->detail['parts']['_default.alt']['outcome']);
    }

    #[Test]
    public function an_entry_level_claim_or_no_output_cannot_erase_owed_fields(): void
    {
        $claimed = $this->storedAsset();
        $silent = $this->storedAsset();
        config(['ai.operations.batch_size' => 1]);
        $this->driver->push(
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $claimed->id, 'outcome' => 'unchanged']]]),
            FakeAiDriver::toolCall('propose_asset_changes', ['unchanged' => [['id' => $claimed->id, 'reason' => 'Looks fine.']]]),
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $claimed->id, 'outcome' => 'skipped', 'reason' => 'Too hard.']]]),
            ...array_fill(0, 9, FakeAiDriver::text('Done, all good.')),
        );

        $operation = $this->approve(['summary' => 'Metadata.', 'outputs' => ['fields' => ['alt'], 'tags' => false]]);

        $ledger = AiOperationItem::query()->get()->keyBy('target_id');
        $this->assertSame(AiOperationItemStatus::Failed, $ledger[$claimed->id]->status);
        $this->assertSame('Too hard.', $ledger[$claimed->id]->detail['note']);
        $this->assertSame(AiOperationItemStatus::Failed, $ledger[$silent->id]->status);
        $this->assertSame('completed', $operation->state->value);
        $this->assertFalse($operation->counts['coverage']['complete']);
        $this->assertSame(['unfinished' => 4], $operation->counts['coverage']['outputs']);
        $this->assertSame(0, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_refused_field_stays_owed_while_the_valid_ones_go_ahead_and_a_retry_can_fix_it(): void
    {
        $asset = $this->storedAsset();
        $this->driver->push(
            FakeAiDriver::toolCall('inspect_assets', ['ids' => [$asset->id]]),
            FakeAiDriver::toolCall('propose_asset_changes', ['assets' => [['id' => $asset->id, 'group' => 'Shots', 'reason' => 'A shoe.', 'fields' => [
                '_default' => ['alt' => str_repeat('a', 126), 'title' => 'Good title', 'caption' => 'Not requested'],
            ]]]]),
            function (Transcript $transcript) use ($asset) {
                $result = json_decode((string) collect($transcript->toArray())->last(fn (array $message) => $message['role'] === 'tool')['content'], true);
                $this->assertSame(['_default.alt'], $result['outputs'][$asset->id]['owed']);
                $this->assertArrayHasKey('_default.alt', $result['outputs'][$asset->id]['invalid']);
                $this->assertStringContainsString('not an output', $result['outputs'][$asset->id]['invalid']['_default.caption']);

                return FakeAiDriver::toolCall('propose_asset_changes', ['assets' => [['id' => $asset->id, 'group' => 'Shots', 'reason' => 'A shoe.', 'fields' => ['_default' => ['alt' => 'A short alt']]]]]);
            },
            FakeAiDriver::text('Fixed.'),
        );

        $operation = $this->approve(['summary' => 'Metadata.', 'languages' => ['_default'], 'outputs' => ['fields' => ['alt', 'title'], 'tags' => false]]);

        $this->assertSame(['fields._default.alt', 'fields._default.title'], AiChangesetItem::query()->orderBy('path')->pluck('path')->all());
        $this->assertNotEmpty(AiChangesetItem::query()->where('path', 'fields._default.title')->sole()->review['evidence']);
        $this->assertTrue($operation->counts['coverage']['complete']);
    }

    #[Test]
    public function stored_values_settle_in_code_and_a_field_the_folder_lacks_is_a_visible_skip(): void
    {
        $this->asset(['data' => ['fields' => ['_default' => ['alt' => 'Kept']]], 'tags' => ['x']]);
        $this->space->settings = [...$this->space->settings->toArray(), 'languages' => []];
        $this->space->save();

        $operation = $this->approve(['summary' => 'Fill.', 'outputs' => ['fields' => ['alt'], 'tags' => true]]);

        $item = AiOperationItem::query()->sole();
        $this->assertSame(0, $this->driver->calls);
        $this->assertSame([AiOperationItemStatus::Unchanged, 'has_value'], [$item->status, $item->reason]);
        $this->assertTrue($operation->counts['coverage']['complete']);
        $this->assertSame(['satisfied' => 2], $operation->counts['coverage']['outputs']);
    }

    #[Test]
    public function a_plan_may_keep_a_replaceable_value_only_by_saying_so(): void
    {
        $asset = $this->asset(['data' => ['fields' => ['_default' => ['alt' => 'Old']]]]);
        $this->driver->push(
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $asset->id, 'outcome' => 'unchanged']]]),
            FakeAiDriver::text('Kept it.'),
        );

        $operation = $this->approve(['summary' => 'Rewrite alts.', 'languages' => ['_default'], 'permissions' => ['overwrite' => true], 'overwrite_fields' => ['alt'], 'outputs' => ['fields' => ['alt'], 'tags' => false]]);

        $item = AiOperationItem::query()->sole();
        $this->assertSame(2, $this->driver->calls);
        $this->assertSame(AiOperationItemStatus::Unchanged, $item->status);
        $this->assertTrue($operation->counts['coverage']['complete']);
    }

    #[Test]
    public function a_plan_without_outputs_keeps_its_hash_and_its_coverage_stays_explicitly_unverified(): void
    {
        $asset = $this->storedAsset();
        $this->driver->push(
            FakeAiDriver::toolCall('inspect_assets', ['ids' => [$asset->id]]),
            FakeAiDriver::toolCall('propose_asset_changes', ['assets' => [['id' => $asset->id, 'group' => 'Shots', 'reason' => 'A shoe.', 'fields' => ['_default' => ['alt' => 'Alt']]]]]),
            FakeAiDriver::text('Done.'),
        );

        $operation = $this->approve(['summary' => 'Metadata.']);

        $this->assertArrayNotHasKey('outputs', $operation->approved_plan);
        $this->assertSame(AiOperationItemStatus::Proposed, AiOperationItem::query()->sole()->status);
        $this->assertArrayNotHasKey('expected_parts', AiOperationItem::query()->sole()->detail ?? []);
        $this->assertFalse($operation->counts['coverage']['verified']);
        $this->assertNull($operation->counts['coverage']['complete']);
        $this->assertSame(['proposed' => 1], $operation->counts['coverage']['entries']);
    }

    #[Test]
    public function outputs_outside_the_real_field_rules_are_refused_with_the_plan(): void
    {
        $this->asset();
        $id = $this->createOperation();

        foreach ([['fields' => ['nope'], 'tags' => false], ['fields' => [], 'tags' => false], ['fields' => ['alt'], 'tags' => 'yes']] as $outputs) {
            $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'Bad.', 'outputs' => $outputs]])
                ->assertStatus(422)->assertJsonValidationErrors('plan.outputs');
        }
    }

    #[Test]
    public function a_translation_locale_keeps_unit_progress_and_a_claim_does_not_settle_what_it_owes(): void
    {
        $this->space->settings = [...$this->space->settings->toArray(), 'i18n_mode' => 'overlay'];
        $this->space->save();
        $page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => ['title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true], 'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true]],
        ]);
        $source = new Content;
        $source->forceFill(['block_id' => $page->id, 'name' => 'About', 'slug' => 'about', 'full_slug' => '/about', 'language_iso' => 'en']);
        $source->id = strtolower((string) Str::ulid());
        $source->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $source->id, 'content' => ['title' => 'About', 'summary' => 'Who we are'], 'created_by_id' => $this->user->id])->id;
        $source->save();
        $propose = fn (array $units) => FakeAiDriver::toolCall('propose_translation', ['source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'name' => 'Über', 'units' => $units]);

        $this->driver->push(
            $propose(['title' => 'Über']),
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $source->id, 'outcome' => 'unchanged']]]),
            function () use ($source) {
                $item = AiOperationItem::query()->sole();
                $this->assertSame(AiOperationItemStatus::Running, $item->status);
                $this->assertArrayNotHasKey('de', $item->detail['parts'] ?? []);
                $this->assertSame(['written' => ['title'], 'remaining' => ['summary']], $item->detail['units']['de']);

                return FakeAiDriver::toolCall('propose_translation', ['source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'version_id' => Content::query()->where('i18n_parent_id', $source->id)->value('current_version_id'), 'units' => ['summary' => 'Wer wir sind']]);
            },
            FakeAiDriver::text('Done.'),
        );

        $operation = AiOperation::query()->findOrFail($this->postJson($this->url('ai/operations'), ['kind' => 'translation', 'prompt' => 'Translate.', 'scope' => ['type' => 'content']])->assertCreated()->json('data.id'));
        $this->approveOperation($operation, ['summary' => 'German.', 'locales' => ['de'], 'include' => ['missing', 'empty']]);

        $item = AiOperationItem::query()->sole();
        $operation->refresh();
        $this->assertSame(AiOperationItemStatus::Written, $item->status);
        $this->assertSame(['outcome' => 'written'], $item->detail['parts']['de']);
        $this->assertSame(['written' => ['title', 'summary'], 'remaining' => []], $item->detail['units']['de']);
        $this->assertSame(['verified' => true, 'complete' => true, 'outputs' => ['written' => 1], 'entries' => ['written' => 1]], $operation->counts['coverage']);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    private function createOperation(): string
    {
        return $this->postJson($this->url('ai/operations'), ['kind' => 'assets', 'prompt' => 'Describe.', 'scope' => ['type' => 'asset']])->assertCreated()->json('data.id');
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

    /** @param  array<string, mixed>  $attributes */
    private function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'folder_id' => null,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'metadata' => ['width' => 100, 'height' => 100],
            'data' => [],
            'tags' => [],
            ...$attributes,
        ]);
    }

    private function storedAsset(): Asset
    {
        $asset = $this->asset();
        app(StorageService::class)->getStorage($this->storage)->put($asset->path, UploadedFile::fake()->image('a.jpg', 20, 20)->getContent());

        return $asset;
    }
}
