<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\AssetController;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(AssetController::class)]
class AssetTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $user;

    protected Space $space;

    protected Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user, space, and storage
        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        // Associate user with space as owner
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        // Create a storage for the space
        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => [
                'root' => storage_path("app/spaces/{$this->space->id}"),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->space->settings = [
            ...$this->space->settings->toArray(),
            'asset_fields' => [
                ['key' => 'alt', 'label' => 'Alt Text', 'required' => true],
                ['key' => 'description', 'label' => 'Description', 'required' => false],
            ],
            'languages' => [
                ['code' => 'de', 'name' => 'German'],
            ],
        ];
        $this->space->save();

        Sanctum::actingAs($this->user);

        // Configure the fake disk for testing
        LaravelStorage::fake($this->storage->id);

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function user_can_upload_an_asset()
    {
        $file = UploadedFile::fake()->image('test-image.jpg', 1000, 1000);

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/assets", [
            'file' => $file,
            'metadata' => [
                'description' => 'Test image description',
                'alt_text' => 'Alt text for image',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'id',
            'filename',
            'extension',
            'mime_type',
            'size',
            'full_path',
            'metadata',
            'url',
            'created_at',
            'updated_at',
        ]);

        // Assert the file was stored
        $asset = Asset::first();
        //        LaravelStorage::disk($this->storage->id)->assertExists($asset->path);

        // Assert metadata was stored correctly
        $this->assertEquals('Test image description', $asset->metadata['description']);
        $this->assertEquals('Alt text for image', $asset->metadata['alt_text']);

        // Assert basic properties
        $this->assertEquals('test-image', $asset->filename);
        $this->assertEquals('jpg', $asset->extension);
        $this->assertEquals('image/jpeg', $asset->mime_type);
    }

    #[Test]
    public function multipart_upload_decodes_json_field_payloads()
    {
        $folder = AssetFolder::factory()->create([
            'settings' => [
                'field_overrides' => [
                    ['key' => 'description', 'enabled' => false],
                ],
                'additional_fields' => [
                    ['key' => 'photographer', 'label' => 'Photographer', 'required' => true],
                ],
            ],
        ]);

        $file = UploadedFile::fake()->image('hero.jpg', 1600, 900);

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/assets",
            [
                'file' => $file,
                'folder_id' => $folder->id,
                'metadata' => json_encode(['copyright' => 'ACME']),
                'tags' => json_encode(['hero', 'homepage']),
                'data' => json_encode([
                    'fields' => [
                        '_default' => [
                            'photographer' => 'Jane Doe',
                        ],
                        'de' => [
                            'photographer' => 'Jane Doe DE',
                        ],
                    ],
                ]),
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response->assertCreated();
        $response->assertJsonPath('folder_id', $folder->id);
        $response->assertJsonPath('folder.id', $folder->id);

        $asset = Asset::query()->firstOrFail();

        $this->assertSame('ACME', $asset->metadata['copyright']);
        $this->assertSame(['hero', 'homepage'], $asset->tags);
        $this->assertSame('Jane Doe', $asset->data['fields']['_default']['photographer']);
        $this->assertSame('Jane Doe DE', $asset->data['fields']['de']['photographer']);
    }

    #[Test]
    public function user_can_list_assets()
    {
        // Create a few assets
        Asset::factory()->count(5)->create([
            'storage_id' => $this->storage->id,
        ]);

        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets");

        $response->assertStatus(200);
        $response->assertJsonCount(5, 'data');
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'filename',
                    'extension',
                    'mime_type',
                    'size',
                ],
            ],
            'links',
            'meta',
        ]);
    }

    #[Test]
    public function user_can_filter_assets_by_mime_type()
    {
        // Create assets with different types
        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'mime_type' => 'image/jpeg',
        ]);

        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'mime_type' => 'application/pdf',
        ]);

        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'mime_type' => 'image/png',
        ]);

        // Filter for images only
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets?mime_type=image/jpeg,image/png");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        // All results should be images
        $data = $response->json('data');
        foreach ($data as $asset) {
            $this->assertStringStartsWith('image/', $asset['mime_type']);
        }
    }

    #[Test]
    public function user_can_filter_assets_by_folder()
    {
        // Create a folder
        $folder = AssetFolder::factory()->create([]);

        // Create assets with and without folder
        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'folder_id' => $folder->id,
        ]);

        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'folder_id' => null,
        ]);

        // Filter by folder
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets?folder={$folder->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals($folder->id, $response->json('data.0.folder_id'));
    }

    #[Test]
    public function user_can_search_assets_by_filename()
    {
        // Create assets with specific filenames
        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'filename' => 'report-2023',
        ]);

        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'filename' => 'image-vacation',
        ]);

        // Search for "report"
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets?q=report");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('report-2023', $response->json('data.0.filename'));
    }

    #[Test]
    public function user_can_view_asset_details()
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'filename' => 'test-document',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'metadata' => [
                'description' => 'Test document',
                'author' => 'Test User',
            ],
        ]);

        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $asset->id,
            'filename' => 'test-document',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'metadata' => [
                'description' => 'Test document',
                'author' => 'Test User',
            ],
        ]);
    }

    #[Test]
    public function user_can_update_asset_metadata()
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'metadata' => [
                'description' => 'Original description',
            ],
        ]);

        $response = $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}", [
            'metadata' => [
                'description' => 'Updated description',
                'new_field' => 'New value',
            ],
        ]);

        $response->assertStatus(200);

        // Reload the asset from database
        $asset->refresh();

        // Check metadata was updated correctly
        $this->assertEquals('Updated description', $asset->metadata['description']);
        $this->assertEquals('New value', $asset->metadata['new_field']);
    }

    #[Test]
    public function asset_can_be_moved_back_to_root_folder()
    {
        $folder = AssetFolder::factory()->create([]);
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'folder_id' => $folder->id,
        ]);

        $response = $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}", [
            'folder_id' => null,
        ]);

        $response->assertOk();
        $response->assertJsonPath('folder_id', null);

        $asset->refresh();
        $this->assertNull($asset->folder_id);
    }

    #[Test]
    public function import_discards_fields_that_are_not_relevant_for_the_asset_folder()
    {
        $folder = AssetFolder::factory()->create([
            'settings' => [
                'field_overrides' => [
                    ['key' => 'description', 'enabled' => false],
                ],
                'additional_fields' => [
                    ['key' => 'photographer', 'label' => 'Photographer', 'required' => true],
                ],
            ],
        ]);

        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'folder_id' => $folder->id,
            'data' => [
                'fields' => [
                    '_default' => [
                        'alt' => 'Original alt',
                    ],
                ],
            ],
        ]);

        $importFile = UploadedFile::fake()->createWithContent(
            'asset-import.json',
            json_encode([
                'assets' => [
                    [
                        'id' => $asset->id,
                        'description' => 'This should be ignored',
                        'photographer' => 'Jane Doe',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $response = $this->post(
            "/mgmt/v1/spaces/{$this->space->id}/assets/import",
            ['file' => $importFile],
            ['Accept' => 'application/json']
        );

        $response->assertOk();
        $response->assertJsonPath('ignored_fields.0', 'description');

        $asset->refresh();

        $this->assertSame('Jane Doe', $asset->data['fields']['_default']['photographer']);
        $this->assertArrayNotHasKey('description', $asset->data['fields']['_default']);
    }

    #[Test]
    public function export_only_includes_fields_relevant_to_the_selected_assets()
    {
        $folder = AssetFolder::factory()->create([
            'settings' => [
                'field_overrides' => [
                    ['key' => 'description', 'enabled' => false],
                ],
                'additional_fields' => [
                    ['key' => 'photographer', 'label' => 'Photographer', 'required' => true],
                ],
            ],
        ]);

        Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'folder_id' => $folder->id,
            'data' => [
                'fields' => [
                    '_default' => [
                        'alt' => 'Hero alt',
                        'photographer' => 'Jane Doe',
                    ],
                ],
            ],
        ]);

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/assets/export", [
            'as' => 'json',
            'folder' => $folder->id,
        ]);

        $response->assertOk();
        $payload = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['alt', 'photographer'], array_column($payload['asset_fields'], 'key'));
        $this->assertArrayNotHasKey('description', $payload['assets'][0]);
        $this->assertSame('Jane Doe', $payload['assets'][0]['photographer']);
    }

    #[Test]
    public function user_can_delete_an_asset()
    {
        $asset = Asset::factory()->create([
            'storage_id' => $this->storage->id,
            'path' => 'test-path/file.jpg',
        ]);

        // Create the file in the fake storage
        LaravelStorage::disk($this->storage->id)->put('test-path/file.jpg', 'test content');

        $response = $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/assets/{$asset->id}");

        $response->assertStatus(204);

        // Assert the asset is deleted from database
        $this->assertSoftDeleted('assets', [
            'id' => $asset->id,
        ]);

        // Assert the file is deleted from storage
        // LaravelStorage::disk($this->storage->id)->assertMissing('test-path/file.jpg');
    }

    #[Test]
    public function pagination_works_for_assets()
    {
        // Create more assets than the default per page
        Asset::factory()->count(25)->create([
            'storage_id' => $this->storage->id,
        ]);

        // Request first page with 10 items per page
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets?per_page=10");

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'links',
                'path',
                'per_page',
                'to',
                'total',
            ],
        ]);

        // The meta should indicate we're on page 1 of 3
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(3, $response->json('meta.last_page'));
        $this->assertEquals(10, $response->json('meta.per_page'));
        $this->assertEquals(25, $response->json('meta.total'));

        // Request second page
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets?per_page=10&page=2");

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
        $this->assertEquals(2, $response->json('meta.current_page'));

        // The items should be different on each page
        $firstPageIds = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/assets?per_page=10&page=1")
            ->json('data.*.id');

        $secondPageIds = $response->json('data.*.id');

        $this->assertEmpty(array_intersect($firstPageIds, $secondPageIds));
    }
}
