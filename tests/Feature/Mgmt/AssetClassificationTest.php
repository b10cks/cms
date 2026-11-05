<?php

namespace Tests\Feature\Mgmt;

use App\Jobs\Space\ClassifyAssetJob;
use App\Models\Management\AiGlossary;
use App\Models\Management\AssetClassificationRun;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\AiRun;
use App\Models\Space\Asset;
use App\Models\Space\AssetTag;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\AssetClassificationService;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

class AssetClassificationTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    private FakeAiDriver $driver;

    private string $cheapRow;

    protected User $user;

    protected Space $space;

    protected Storage $storage;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path("app/spaces/{$this->space->id}"));

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create([
            'settings' => [
                'default_language' => 'en',
                'languages' => [
                    ['code' => 'de', 'name' => 'German'],
                ],
                'asset_fields' => [
                    ['key' => 'title', 'label' => 'Title', 'required' => false],
                    ['key' => 'alt', 'label' => 'Alt', 'required' => false],
                    ['key' => 'description', 'label' => 'Description', 'required' => false],
                    ['key' => 'copyright_holder', 'label' => 'Copyright', 'required' => false],
                ],
            ],
        ]);

        $this->assignSpaceRole($this->space, $this->user, 'owner');

        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => ['root' => storage_path("app/spaces/{$this->space->id}")],
            'driver' => 'local',
            'state' => 'live',
        ]);

        LaravelStorage::fake($this->storage->id);
        Sanctum::actingAs($this->user);
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->space->aiConfigs()->create([
            'name' => 'Vision',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.3,
            'max_tokens' => 1024,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function it_queues_only_image_assets_with_empty_fields(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);

        $empty = $this->createAsset();
        $filled = $this->createAsset([
            'data' => ['fields' => [
                '_default' => ['title' => 'T', 'alt' => 'A', 'description' => 'D'],
                'de' => ['title' => 'T', 'alt' => 'A', 'description' => 'D'],
            ]],
        ]);
        $pdf = $this->createAsset(['mime_type' => 'application/pdf', 'extension' => 'pdf']);

        $response = $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection',
            'asset_ids' => [$empty->id, $filled->id, $pdf->id],
        ]);

        $response->assertOk()->assertJsonPath('data.queued', 1)->assertJsonPath('data.skipped', 1);

        Queue::assertPushed(ClassifyAssetJob::class, 1);
        Queue::assertPushed(ClassifyAssetJob::class, fn (ClassifyAssetJob $job) => $job->assetId === $empty->id
            && $job->languages === ['_default', 'de']);
    }

    #[Test]
    public function it_limits_target_languages_to_the_request(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);

        $germanOnlyMissing = $this->createAsset([
            'data' => ['fields' => ['_default' => ['title' => 'T', 'alt' => 'A', 'description' => 'D']]],
        ]);

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection',
            'asset_ids' => [$germanOnlyMissing->id],
            'languages' => ['_default'],
        ])->assertOk()->assertJsonPath('data.queued', 0)->assertJsonPath('data.skipped', 1);

        Queue::assertNotPushed(ClassifyAssetJob::class);
    }

    #[Test]
    public function it_queues_the_whole_library_for_scope_all(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);

        $this->createAsset();
        $this->createAsset();
        $this->createAsset(['mime_type' => 'video/mp4', 'extension' => 'mp4']);

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", ['scope' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.queued', 2);

        Queue::assertPushed(ClassifyAssetJob::class, 2);
    }

    #[Test]
    public function rate_limited_jobs_have_time_to_finish_a_large_library(): void
    {
        $job = new ClassifyAssetJob($this->space, $this->user->id, 'asset-id', ['_default']);

        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertNotInstanceOf(ShouldBeUnique::class, $job);
        $this->assertCount(2, $job->middleware());
        $this->assertNull(app('queue')->connection('sync')->getJobExpiration($job));
    }

    #[Test]
    public function repeated_requests_are_accepted_and_report_worker_progress(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($asset);
        $url = "/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}";
        $payload = ['scope' => 'selection', 'asset_ids' => [$asset->id]];

        $first = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.queued', 1);
        $second = $this->postJson($url, [...$payload, 'overwrite' => true])->assertOk()->assertJsonPath('data.queued', 1);
        Queue::assertPushed(ClassifyAssetJob::class, 2);

        $runId = $first->json('data.run_id');
        $this->getJson("/mgmt/v1/ai/assets/classify/{$runId}?spaceId={$this->space->id}")
            ->assertOk()->assertJsonPath('data.pending', 1)->assertJsonPath('data.complete', false);

        $this->driver->push(
            FakeAiDriver::text(json_encode(['_default.alt' => 'A red square'])),
            FakeAiDriver::text(json_encode(['_default.alt' => 'A blue square'])),
        );

        $jobs = Queue::pushed(ClassifyAssetJob::class);
        $jobs[0]->handle();
        $jobs[1]->handle();

        $this->getJson("/mgmt/v1/ai/assets/classify/{$runId}?spaceId={$this->space->id}")
            ->assertOk()->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.updated', 1)->assertJsonPath('data.complete', true);
        $this->assertSame(1, AssetClassificationRun::query()->findOrFail($second->json('data.run_id'))->updated);
    }

    #[Test]
    public function a_run_is_visible_only_in_its_space(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset();
        $runId = $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection', 'asset_ids' => [$asset->id],
        ])->assertOk()->json('data.run_id');
        $other = Space::factory()->create();
        $this->assignSpaceRole($other, $this->user, 'owner');

        $this->getJson("/mgmt/v1/ai/assets/classify/{$runId}?spaceId={$other->id}")->assertNotFound();
    }

    #[Test]
    public function final_job_failures_appear_in_run_progress(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset();
        $runId = $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection', 'asset_ids' => [$asset->id],
        ])->assertOk()->json('data.run_id');

        Queue::pushed(ClassifyAssetJob::class)[0]->failed(new \RuntimeException('provider failed'));

        $this->getJson("/mgmt/v1/ai/assets/classify/{$runId}?spaceId={$this->space->id}")
            ->assertOk()->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.failed', 1)->assertJsonPath('data.complete', true);
    }

    #[Test]
    public function decorative_classification_clears_existing_alt_without_overwriting_other_fields(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset([
            'data' => ['fields' => ['_default' => ['title' => 'Keep', 'alt' => 'Old alt', 'description' => 'Keep description']]],
            'metadata' => ['width' => 100, 'height' => 100],
        ]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode(['_default.alt' => 'Model ignored instruction'])));

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection', 'asset_ids' => [$asset->id], 'languages' => ['_default'], 'decorative' => true,
        ])->assertOk();

        $this->assertStringContainsString('decorative', $this->driver->lastSystemPrompt());
        $fresh = $asset->fresh();
        $this->assertSame('', $fresh->data['fields']['_default']['alt']);
        $this->assertSame('Keep', $fresh->data['fields']['_default']['title']);
        $this->assertSame(['_default.alt'], $fresh->metadata['ai_classification']['fields']);
    }

    #[Test]
    public function placement_context_regenerates_only_alt_when_other_fields_are_full(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset([
            'data' => ['fields' => ['_default' => ['title' => 'Keep', 'alt' => 'Old alt', 'description' => 'Keep description']]],
            'metadata' => ['width' => 100, 'height' => 100],
        ]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode(['_default.alt' => 'Explore the summer collection'])));

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection', 'asset_ids' => [$asset->id], 'languages' => ['_default'],
            'alt_context' => 'Linked banner for the summer collection',
        ])->assertOk()->assertJsonPath('data.queued', 1);

        $this->assertStringContainsString('summer collection', $this->driver->lastSystemPrompt());
        $fields = $asset->fresh()->data['fields']['_default'];
        $this->assertSame('Explore the summer collection', $fields['alt']);
        $this->assertSame('Keep', $fields['title']);
        $this->assertSame('Keep description', $fields['description']);
    }

    #[Test]
    public function field_validation_rejects_copy_over_its_field_limit(): void
    {
        $service = app(AssetClassificationService::class);
        $result = $service->parseResponse(json_encode([
            '_default.title' => str_repeat('T', 71),
            '_default.alt' => 'A red square',
            '_default.description' => str_repeat('D', 401),
            '_default.keywords' => 'one, two',
        ]), ['_default.title', '_default.alt', '_default.description', '_default.keywords']);

        $this->assertSame(['_default' => ['alt' => 'A red square']], $result['fields']);
    }

    #[Test]
    public function high_detail_sends_a_larger_native_image_for_small_text(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 1500, 'height' => 800]]);
        app(StorageService::class)->getStorage($this->storage)
            ->put($asset->path, UploadedFile::fake()->image('chart.jpg', 1500, 800)->getContent());

        $this->driver->push(FakeAiDriver::text(json_encode(['_default.alt' => 'A chart'])));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default'], null, false, null, null, false, true))->handle();

        $image = $this->imagePart();
        $this->assertSame('image/jpeg', $image['mime_type']);
        $this->assertSame(1500, getimagesizefromstring(base64_decode($image['data']))[0]);
        $this->assertSame('A chart', $asset->fresh()->data['fields']['_default']['alt']);
    }

    #[Test]
    public function it_rejects_configs_without_vision_support(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: false);

        $asset = $this->createAsset();

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection',
            'asset_ids' => [$asset->id],
        ])->assertStatus(422)->assertJsonPath('reason', 'model_not_vision');

        Queue::assertNotPushed(ClassifyAssetJob::class);
    }

    #[Test]
    public function it_rejects_unknown_languages(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'all',
            'languages' => ['fr'],
        ])->assertStatus(422);

        Queue::assertNotPushed(ClassifyAssetJob::class);
    }

    #[Test]
    public function it_requires_asset_management_rights(): void
    {
        Queue::fake();
        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        Sanctum::actingAs($viewer);

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", ['scope' => 'all'])
            ->assertStatus(403);

        Queue::assertNotPushed(ClassifyAssetJob::class);
    }

    #[Test]
    public function uploads_are_queued_only_when_auto_classification_is_on(): void
    {
        Queue::fake();

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/assets", [
            'file' => UploadedFile::fake()->image('first.jpg', 100, 100),
        ])->assertStatus(201);

        Queue::assertNotPushed(ClassifyAssetJob::class);

        $this->enableAutoClassification();

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/assets", [
            'file' => UploadedFile::fake()->create('doc.pdf', 12, 'application/pdf'),
        ])->assertStatus(201);

        Queue::assertNotPushed(ClassifyAssetJob::class);

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/assets", [
            'file' => UploadedFile::fake()->image('second.jpg', 120, 80),
        ])->assertStatus(201);

        Queue::assertPushed(ClassifyAssetJob::class, fn (ClassifyAssetJob $job) => $job->assetId === $response->json('id'));
    }

    #[Test]
    public function the_job_fills_empty_fields_and_keeps_existing_values(): void
    {
        $this->mockRegistry(supportsVision: true);

        $asset = $this->createAsset([
            'data' => ['fields' => ['_default' => ['title' => 'Keep me']]],
            'metadata' => ['width' => 100, 'height' => 100],
        ]);
        $this->storeImage($asset);

        $this->driver->push(FakeAiDriver::text(json_encode([
            '_default.title' => 'Overwrite attempt',
            '_default.alt' => 'A red square',
            '_default.description' => 'A plain red square on white.',
            'de.title' => 'Rotes Quadrat',
            'de.copyright_holder' => 'Not allowed',
        ])));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default', 'de']))->handle();

        $system = $this->driver->lastSystemPrompt();
        preg_match('/exact keys: (\[[^\n]*\])/', $system, $keys);
        $targetKeys = json_decode($keys[1] ?? '[]', true);
        $this->assertStringContainsString('de: write in German', $system);
        $this->assertContains('_default.alt', $targetKeys);
        $this->assertNotContains('_default.title', $targetKeys);
        $this->assertStringNotContainsString('copyright_holder', $keys[1]);
        $this->assertStringContainsString('"existing_values":{"_default.title":"Keep me"}', $this->driver->lastUserText());
        $this->assertSame('image/jpeg', $this->imagePart()['mime_type']);

        $fields = $asset->fresh()->data['fields'];

        $this->assertSame('Keep me', $fields['_default']['title']);
        $this->assertSame('A red square', $fields['_default']['alt']);
        $this->assertSame('Rotes Quadrat', $fields['de']['title']);
        $this->assertArrayNotHasKey('copyright_holder', $fields['de']);
    }

    #[Test]
    public function the_job_sends_context_and_records_provenance(): void
    {
        $this->mockRegistry(supportsVision: true);

        $asset = $this->createAsset([
            'filename' => 'team-offsite_2026',
            'data' => ['fields' => ['_default' => ['title' => 'Team offsite']]],
            'metadata' => ['width' => 100, 'height' => 100],
        ]);
        $this->storeImage($asset);

        $this->driver->push(FakeAiDriver::text(json_encode(['de.title' => 'Team-Offsite', '_default.alt' => 'Colleagues around a table'])));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default', 'de']))->handle();

        $text = $this->driver->lastUserText();
        $this->assertStringContainsString('"filename":"team offsite 2026"', $text);
        $this->assertStringContainsString('"_default.title":"Team offsite"', $text);
        $this->assertMatchesRegularExpression('/<context-[A-Za-z0-9]{8}>/', $text);
        $this->assertStringContainsString('at most 125 characters', $this->driver->lastSystemPrompt());

        $provenance = $asset->fresh()->metadata['ai_classification'];

        $this->assertSame('openai:gpt-4o-mini', $provenance['model']);
        $this->assertEqualsCanonicalizing(['de.title', '_default.alt'], $provenance['fields']);
        $this->assertNotEmpty($provenance['at']);
    }

    #[Test]
    public function provenance_only_records_values_written_after_an_editor_update(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => [
            'width' => 100,
            'height' => 100,
            'ai_classification' => ['fields' => ['_default.title']],
        ]]);
        $this->storeImage($asset);

        $this->driver->push(function () use ($asset) {
            $asset->update(['data' => ['fields' => ['_default' => ['title' => 'Editor title']]]]);

            return FakeAiDriver::text(json_encode([
                '_default.title' => 'AI title',
                '_default.alt' => 'AI alt',
            ]));
        });

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default']))->handle();

        $fresh = $asset->fresh();
        $this->assertSame('Editor title', $fresh->data['fields']['_default']['title']);
        $this->assertSame('AI alt', $fresh->data['fields']['_default']['alt']);
        $this->assertSame(['_default.alt'], $fresh->metadata['ai_classification']['fields']);
    }

    #[Test]
    public function classification_rejects_oversized_source_pixels_before_decoding(): void
    {
        $this->mockRegistry(supportsVision: true);
        config()->set('ilum.max_source_pixels', 5_000);

        $asset = $this->createAsset(['metadata' => ['width' => 2000, 'height' => 2000]]);
        $this->storeImage($asset);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source image exceeds the configured pixel limit.');

        try {
            (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default']))->handle();
        } finally {
            $this->assertSame(0, $this->driver->calls);
        }
    }

    #[Test]
    public function classification_streams_a_source_that_needs_conversion(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 2000, 'height' => 2000]]);
        $this->storeImage($asset);

        $this->driver->push(FakeAiDriver::text(json_encode(['_default.alt' => 'A small image'])));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default']))->handle();

        $this->assertSame('image/webp', $this->imagePart()['mime_type']);
        $this->assertStringStartsWith('RIFF', base64_decode($this->imagePart()['data']));

        $this->assertSame('A small image', $asset->fresh()->data['fields']['_default']['alt']);
    }

    #[Test]
    public function overwrite_regenerates_filled_fields(): void
    {
        Queue::fake();
        $this->mockRegistry(supportsVision: true);

        $filled = $this->createAsset([
            'data' => ['fields' => [
                '_default' => ['title' => 'T', 'alt' => 'A', 'description' => 'D'],
                'de' => ['title' => 'T', 'alt' => 'A', 'description' => 'D'],
            ]],
        ]);

        $this->postJson("/mgmt/v1/ai/assets/classify?spaceId={$this->space->id}", [
            'scope' => 'selection',
            'asset_ids' => [$filled->id],
            'overwrite' => true,
        ])->assertOk()->assertJsonPath('data.queued', 1);

        Queue::assertPushed(ClassifyAssetJob::class, fn (ClassifyAssetJob $job) => $job->overwrite === true);
    }

    #[Test]
    public function the_job_assigns_taxonomy_tags_when_enabled(): void
    {
        $this->mockRegistry(supportsVision: true);
        $this->space->settings = [
            ...$this->space->settings->toArray(),
            'ai' => ['asset_classification' => ['suggest_tags' => true, 'allowed_fields' => ['alt']]],
        ];
        $this->space->save();

        $outdoor = AssetTag::query()->create(['name' => 'Outdoor']);
        AssetTag::query()->create(['name' => 'People']);
        $existing = AssetTag::query()->create(['name' => 'Archive']);

        $asset = $this->createAsset([
            'tags' => [$existing->id],
            'data' => ['fields' => ['_default' => ['alt' => 'Filled'], 'de' => ['alt' => 'Gefüllt']]],
            'metadata' => ['width' => 100, 'height' => 100],
        ]);
        $this->storeImage($asset);

        $this->driver->push(FakeAiDriver::text(json_encode(['tags' => ['outdoor', 'Made up']])));

        // Fields are full, but tags are requested with overwrite so the job still runs.
        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default', 'de'], null, true))->handle();

        $this->assertStringContainsString('"outdoor"', $this->driver->lastSystemPrompt());
        $this->assertStringContainsString('Also return matching "tags".', $this->driver->lastSystemPrompt());

        $fresh = $asset->fresh();

        $this->assertEqualsCanonicalizing([$existing->id, $outdoor->id], $fresh->tags);
        $this->assertSame('Filled', $fresh->data['fields']['_default']['alt']);
        $this->assertSame([$outdoor->id], $fresh->metadata['ai_classification']['tags']);
    }

    #[Test]
    public function the_job_logs_and_stops_when_ai_is_unavailable(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($asset);

        $this->partialMock(AiRunService::class, function ($mock) {
            $mock->shouldReceive('runToCompletion')->once()->andThrow(AiServiceException::planExcluded());
        });

        Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'unavailable'));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default']))->handle();

        $this->assertNull($asset->fresh()->data['fields'] ?? null);
    }

    #[Test]
    public function the_job_retries_a_temporary_provider_failure(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($asset);

        $this->partialMock(AiRunService::class, function ($mock) {
            $mock->shouldReceive('runToCompletion')->once()->andThrow(AiServiceException::providerUnavailable());
        });

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage('currently unavailable');

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default']))->handle();
    }

    #[Test]
    public function the_job_retries_when_the_provider_returns_no_result(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($asset);

        $this->driver->push(FakeAiDriver::text(''));

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage('did not return a usable result');

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default']))->handle();
    }

    #[Test]
    public function partial_output_saves_what_is_valid_and_reports_every_other_requested_value(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset([
            'data' => ['fields' => ['_default' => ['title' => 'Keep me']]],
            'metadata' => ['width' => 100, 'height' => 100],
        ]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode([
            '_default.title' => 'Overwrite attempt',
            '_default.alt' => 'A red square',
            '_default.description' => str_repeat('x', 401),
            'de.alt' => '',
            'de.description' => ['nested'],
        ])));
        $run = AssetClassificationRun::query()->create(['space_id' => $this->space->id, 'queued' => 1, 'enqueuing' => false]);

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default', 'de'], runId: $run->id))->handle();

        $fresh = $asset->fresh();
        $this->assertSame(['title' => 'Keep me', 'alt' => 'A red square'], $fresh->data['fields']['_default']);
        $this->assertArrayNotHasKey('de', $fresh->data['fields']);
        $this->assertSame('partial', $fresh->metadata['ai_classification']['report']['status']);

        $data = $this->getJson("/mgmt/v1/ai/assets/classify/{$run->id}?spaceId={$this->space->id}")
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.field_report.partial', 1)
            ->assertJsonPath('data.field_report.complete', 0)
            ->json('data.field_report.assets.0');
        $this->assertSame($asset->id, $data['asset_id']);
        $this->assertSame(['_default.alt'], $data['written']);
        $this->assertSame(['de.alt'], $data['uninferable']);
        $this->assertEqualsCanonicalizing(['_default.description', 'de.description'], array_column($data['rejected'], 'key'));
        $this->assertSame(['de.title'], $data['missing']);
        $this->assertSame('partial', $data['languages']['_default']['status']);
        $this->assertSame('none', $data['languages']['de']['status']);
    }

    #[Test]
    public function generated_copy_is_checked_per_language_and_only_violations_are_refused(): void
    {
        $this->mockRegistry(supportsVision: true);
        $glossary = $this->classificationGlossary();
        $this->glossaryRow($glossary, ['de' => ['avoid' => ['klimaneutral*']]]);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100], 'tags' => []]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode([
            '_default.title' => 'Office chair',
            '_default.alt' => 'A cheap chair',
            'de.title' => 'Ein cheap Stuhl',
            'de.alt' => 'Ein klimaneutraler Stuhl',
        ])));
        $classification = AssetClassificationRun::query()->create(['space_id' => $this->space->id, 'queued' => 1, 'enqueuing' => false]);

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default', 'de'], runId: $classification->id))->handle();

        $fields = $asset->fresh()->data['fields'];
        $this->assertSame(['title' => 'Office chair'], $fields['_default']);
        $this->assertSame(['title' => 'Ein cheap Stuhl'], $fields['de'], 'English wording is no rule in German, and the valid value stays.');
        $this->assertSame('partial', $asset->fresh()->metadata['ai_classification']['report']['status']);
        $this->assertCount(1, $this->driver->transcripts, 'One pass: a refused value stays empty for the next classification.');

        $report = $this->getJson("/mgmt/v1/ai/assets/classify/{$classification->id}?spaceId={$this->space->id}")
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.field_report.partial', 1)
            ->json('data.field_report.assets.0');
        $this->assertEqualsCanonicalizing(['_default.title', 'de.title'], $report['written']);
        $this->assertEqualsCanonicalizing([
            ['key' => '_default.alt', 'reason' => 'The glossary avoids "cheap", write "affordable".'],
            ['key' => 'de.alt', 'reason' => 'The glossary avoids "klimaneutraler".'],
        ], $report['rejected']);
        $run = AiRun::query()->where('surface', 'asset')->sole();
        $this->assertEqualsCanonicalizing(['_default.alt', 'de.alt'], array_column($run->result['glossary_findings'], 'key'));
    }

    #[Test]
    public function the_model_reads_the_wording_of_each_requested_language_before_it_writes(): void
    {
        $this->mockRegistry(supportsVision: true);
        $glossary = $this->classificationGlossary();
        $this->glossaryRow($glossary, ['en' => 'mattress', 'de' => 'Matratze']);
        $this->glossaryRow($glossary, ['fr' => ['avoid' => ['matelas']]]);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100], 'data' => ['fields' => ['_default' => ['title' => 'A mattress']]]]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode(['de.alt' => 'Ein Stuhl'])));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['de']))->handle();

        $system = collect($this->driver->transcripts[0])->firstWhere('role', 'system')['content'];
        $this->assertStringContainsString('### Writing de', $system);
        $this->assertStringContainsString('"mattress": write "Matratze" in de', $system, 'A hint in another language still names the wording to write.');
        $this->assertStringContainsString('never what you describe', $system);
        $this->assertStringNotContainsString('cheap', $system, 'English wording is not asked of a German answer.');
        $this->assertStringNotContainsString('matelas', $system);

        $this->driver->push(FakeAiDriver::text(json_encode(['_default.alt' => 'A chair', 'de.title' => 'Stuhl'])));
        $second = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100], 'tags' => []]);
        $this->storeImage($second);

        (new ClassifyAssetJob($this->space, $this->user->id, $second->id, ['_default', 'de']))->handle();

        $system = collect($this->driver->transcripts[1])->firstWhere('role', 'system')['content'];
        $this->assertStringContainsString('### Writing en', $system);
        $this->assertStringContainsString('"cheap" (write "affordable")', $system);
    }

    #[Test]
    public function the_default_language_key_is_checked_in_the_language_of_the_space(): void
    {
        $this->mockRegistry(supportsVision: true);
        $this->space->settings = [...$this->space->settings->toArray(), 'default_language' => 'de', 'languages' => []];
        $this->space->save();
        $glossary = $this->classificationGlossary();
        $this->glossaryRow($glossary, ['de' => ['use' => 'CO2-arm', 'avoid' => ['klimaneutral*']]]);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100], 'tags' => []]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode(['_default.title' => 'Klimaneutrale Lampe', '_default.alt' => 'A cheap lamp'])));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default']))->handle();

        $this->assertSame(['alt' => 'A cheap lamp'], $asset->fresh()->data['fields']['_default']);
        $this->assertSame('The glossary avoids "Klimaneutrale", write "CO2-arm".', $asset->fresh()->metadata['ai_classification']['report']['rejected'][0]['reason']);
    }

    #[Test]
    public function the_pinned_policy_decides_and_an_edit_saved_meanwhile_stays(): void
    {
        $this->mockRegistry(supportsVision: true);
        $glossary = $this->classificationGlossary();
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100], 'tags' => []]);
        $this->storeImage($asset);
        $this->driver->push(function () use ($glossary, $asset) {
            $this->glossaryRow($glossary, ['de' => ['avoid' => ['Stuhl']]]);
            $this->changeGlossaryRow($glossary, $this->cheapRow, ['en' => ['use' => 'affordable']]);
            $asset->fresh()->forceFill(['data' => ['fields' => ['de' => ['title' => 'Von Hand']]]])->save();

            return FakeAiDriver::text(json_encode([
                '_default.alt' => 'A cheap chair',
                'de.alt' => 'Ein Stuhl',
                'de.title' => 'Generiert',
            ]));
        });

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default', 'de']))->handle();

        $fields = $asset->fresh()->data['fields'];
        $this->assertArrayNotHasKey('_default', $fields, 'The ban the run started with still holds.');
        $this->assertSame(['title' => 'Von Hand', 'alt' => 'Ein Stuhl'], $fields['de'], 'A ban added later is not this run\'s, and the edit stays.');
    }

    #[Test]
    public function refusing_every_value_saves_nothing_and_keeps_what_was_there(): void
    {
        $this->mockRegistry(supportsVision: true);
        $this->classificationGlossary();
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100], 'tags' => [], 'data' => ['fields' => ['_default' => ['alt' => 'A chair']]]]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode(['_default.alt' => 'A cheap chair'])));
        $classification = AssetClassificationRun::query()->create(['space_id' => $this->space->id, 'queued' => 1, 'enqueuing' => false]);

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default'], overwrite: true, runId: $classification->id))->handle();

        $this->assertSame('A chair', $asset->fresh()->data['fields']['_default']['alt']);
        $this->getJson("/mgmt/v1/ai/assets/classify/{$classification->id}?spaceId={$this->space->id}")
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.field_report.none', 1)
            ->assertJsonPath('data.field_report.assets.0.rejected.0.key', '_default.alt');
    }

    #[Test]
    public function the_report_tells_a_decorative_alt_from_a_missing_one_and_output_without_usable_values(): void
    {
        $this->mockRegistry(supportsVision: true);
        $this->space->settings = [...$this->space->settings->toArray(), 'ai' => ['asset_classification' => ['allowed_fields' => ['title', 'alt']]]];
        $this->space->save();
        $decorative = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $unusable = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($decorative);
        $this->storeImage($unusable);
        $report = fn (string $id) => AiRun::query()->where('surface', 'asset')->where('result->classification->asset_id', $id)->sole()->result['classification'];

        $this->driver->push(FakeAiDriver::text(json_encode(['_default.title' => 'Ornament', '_default.alt' => 'Ignored'])));
        (new ClassifyAssetJob($this->space, $this->user->id, $decorative->id, ['_default'], decorative: true))->handle();
        $this->assertSame('', $decorative->fresh()->data['fields']['_default']['alt']);
        $this->assertSame('complete', $report($decorative->id)['status']);
        $this->assertSame(['_default.alt'], $report($decorative->id)['intentionally_empty']);
        $this->assertSame([], $report($decorative->id)['missing']);

        $this->driver->push(FakeAiDriver::text(json_encode(['_default.title' => str_repeat('x', 71)])));
        (new ClassifyAssetJob($this->space, $this->user->id, $unusable->id, ['_default']))->handle();
        $this->assertSame([], $unusable->fresh()->data);
        $this->assertSame('none', $report($unusable->id)['status']);
        $this->assertSame(['_default.alt'], $report($unusable->id)['missing']);
        $this->assertSame('at most 70 characters', $report($unusable->id)['rejected'][0]['reason']);
    }

    #[Test]
    public function relevant_tags_beyond_the_prompt_limit_are_offered_named_tags_resolve_and_the_subset_is_stated(): void
    {
        $this->mockRegistry(supportsVision: true);
        $this->space->settings = [...$this->space->settings->toArray(), 'ai' => ['asset_classification' => ['suggest_tags' => true, 'allowed_fields' => ['alt']]]];
        $this->space->save();
        foreach (range(1, 205) as $number) {
            AssetTag::query()->create(['name' => sprintf('Common %03d', $number)]);
        }
        $zebra = AssetTag::query()->create(['name' => 'Zz Zebra']);
        $yak = AssetTag::query()->create(['name' => 'Zz Yak']);
        $asset = $this->createAsset(['filename' => 'zebra-crossing.jpg', 'tags' => [], 'metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($asset);
        $this->driver->push(FakeAiDriver::text(json_encode(['_default.alt' => 'A zebra', 'de.alt' => 'Ein Zebra', 'tags' => ['zz zebra', 'ZZ YAK', 'Made up']])));

        (new ClassifyAssetJob($this->space, $this->user->id, $asset->id, ['_default', 'de']))->handle();

        $system = $this->driver->lastSystemPrompt();
        $this->assertStringContainsString('"zz zebra"', $system);
        $this->assertStringNotContainsString('"zz yak"', $system);
        $this->assertStringContainsString('shows 200 of 207 existing tags', $system);
        $this->assertEqualsCanonicalizing([$zebra->id, $yak->id], $asset->fresh()->tags);

        $tags = AiRun::query()->where('surface', 'asset')->sole()->result['classification']['tags'];
        $this->assertSame(['Made up'], $tags['unknown']);
        $this->assertSame(['shown' => 200, 'total' => 207, 'truncated' => true], $tags['taxonomy']);
    }

    private function classificationGlossary(): AiGlossary
    {
        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop', 'enabled' => true]);
        $this->cheapRow = $this->glossaryRow($glossary, ['en' => ['use' => 'affordable', 'avoid' => ['cheap']]]);

        return $glossary;
    }

    private function storeImage(Asset $asset): void
    {
        app(StorageService::class)
            ->getStorage($this->storage)
            ->put($asset->path, UploadedFile::fake()->image('a.jpg', 100, 100)->getContent());
    }

    private function createAsset(array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'folder_id' => null,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'data' => [],
            ...$attributes,
        ]);
    }

    private function enableAutoClassification(): void
    {
        $this->space->settings = [
            ...$this->space->settings->toArray(),
            'ai' => ['asset_classification' => ['auto_on_upload' => true, 'allowed_fields' => ['title', 'alt', 'description']]],
        ];
        $this->space->save();
    }

    private function mockRegistry(bool $supportsVision): void
    {
        $this->driver = $this->fakeAi($supportsVision);
    }

    /**
     * @return array{type: string, mime_type: string, data: string}
     */
    private function imagePart(): array
    {
        return collect(end($this->driver->transcripts))->firstWhere('role', 'user')['content'][1];
    }
}
