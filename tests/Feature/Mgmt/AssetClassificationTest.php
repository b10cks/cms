<?php

namespace Tests\Feature\Mgmt;

use App\Jobs\Space\ClassifyAssetJob;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetTag;
use App\Models\User;
use App\Services\Ai\AiStreamService;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Services\Ai\ModelRegistry;
use App\Services\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AssetClassificationTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

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
        $job = new ClassifyAssetJob($this->space, 'asset-id', ['_default']);

        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame(604800, $job->uniqueFor);
        $this->assertNull(app('queue')->connection('sync')->getJobExpiration($job));
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

        $this->partialMock(AiStreamService::class, function ($mock) {
            $mock->shouldReceive('generateWithMessages')
                ->once()
                ->withArgs(function (Space $space, array $messages): bool {
                    $userContent = $messages[1]['content'];

                    preg_match('/exact keys: (\[[^\n]*\])/', $userContent[0]['text'], $keys);
                    $targetKeys = json_decode($keys[1] ?? '[]', true);

                    return $messages[0]['role'] === 'system'
                        && str_contains($messages[0]['content'], 'de: write in German')
                        && \in_array('_default.alt', $targetKeys, true)
                        && ! \in_array('_default.title', $targetKeys, true)
                        && ! str_contains($keys[1], 'copyright_holder')
                        && str_contains($userContent[0]['text'], '"existing_values":{"_default.title":"Keep me"}')
                        && $userContent[1]['type'] === 'image'
                        && $userContent[1]['mime_type'] === 'image/jpeg';
                })
                ->andReturn(json_encode([
                    '_default.title' => 'Overwrite attempt',
                    '_default.alt' => 'A red square',
                    '_default.description' => 'A plain red square on white.',
                    'de.title' => 'Rotes Quadrat',
                    'de.copyright_holder' => 'Not allowed',
                ]));
        });

        (new ClassifyAssetJob($this->space, $asset->id, ['_default', 'de']))->handle();

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

        $this->partialMock(AiStreamService::class, function ($mock) {
            $mock->shouldReceive('generateWithMessages')
                ->once()
                ->withArgs(function (Space $space, array $messages): bool {
                    $text = $messages[1]['content'][0]['text'];

                    return str_contains($text, '"filename":"team offsite 2026"')
                        && str_contains($text, '"_default.title":"Team offsite"')
                        && preg_match('/<context-[A-Za-z0-9]{8}>/', $text) === 1
                        && str_contains($messages[0]['content'], 'at most 125 characters');
                })
                ->andReturn(json_encode(['de.title' => 'Team-Offsite', '_default.alt' => 'Colleagues around a table']));
        });

        (new ClassifyAssetJob($this->space, $asset->id, ['_default', 'de']))->handle();

        $provenance = $asset->fresh()->metadata['ai_classification'];

        $this->assertSame('openai:gpt-4o-mini', $provenance['model']);
        $this->assertEqualsCanonicalizing(['de.title', '_default.alt'], $provenance['fields']);
        $this->assertNotEmpty($provenance['at']);
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

        $this->partialMock(AiStreamService::class, function ($mock) {
            $mock->shouldReceive('generateWithMessages')
                ->once()
                ->withArgs(fn (Space $space, array $messages): bool => str_contains($messages[0]['content'], '"outdoor"')
                    && str_contains($messages[1]['content'][0]['text'], 'Also return matching "tags".'))
                ->andReturn(json_encode(['tags' => ['outdoor', 'Made up']]));
        });

        // Fields are full, but tags are requested with overwrite so the job still runs.
        (new ClassifyAssetJob($this->space, $asset->id, ['_default', 'de'], null, true))->handle();

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

        $this->partialMock(AiStreamService::class, function ($mock) {
            $mock->shouldReceive('generateWithMessages')->once()->andThrow(AiServiceException::planExcluded());
        });

        Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'unavailable'));

        (new ClassifyAssetJob($this->space, $asset->id, ['_default']))->handle();

        $this->assertNull($asset->fresh()->data['fields'] ?? null);
    }

    #[Test]
    public function the_job_retries_a_temporary_provider_failure(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($asset);

        $this->partialMock(AiStreamService::class, function ($mock) {
            $mock->shouldReceive('generateWithMessages')->once()->andThrow(AiServiceException::providerUnavailable());
        });

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage('currently unavailable');

        (new ClassifyAssetJob($this->space, $asset->id, ['_default']))->handle();
    }

    #[Test]
    public function the_job_retries_when_the_provider_returns_no_result(): void
    {
        $this->mockRegistry(supportsVision: true);
        $asset = $this->createAsset(['metadata' => ['width' => 100, 'height' => 100]]);
        $this->storeImage($asset);

        $this->partialMock(AiStreamService::class, function ($mock) {
            $mock->shouldReceive('generateWithMessages')->once()->andReturn(null);
        });

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage('did not return a usable result');

        (new ClassifyAssetJob($this->space, $asset->id, ['_default']))->handle();
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
        $this->partialMock(ModelRegistry::class, function ($mock) use ($supportsVision) {
            $mock->shouldReceive('findModelForConfig')->andReturn(new AiModelDto(
                id: 'gpt-4o-mini',
                name: 'GPT-4o Mini',
                driver: 'openai',
                capabilities: ['text', 'vision'],
                supportsVision: $supportsVision,
            ));
        });
    }
}
