<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\AssetFolderController;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\AssetFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(AssetFolderController::class)]
class AssetFolderTest extends TestCase
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
            'driver' => 'local',
            'state' => 'live',
        ]);

        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function user_can_create_an_asset_folder()
    {
        $folderData = [
            'name' => 'Test Folder',
            'description' => 'This is a test folder',
            'icon' => 'folder',
            'color' => '#4ade80',
        ];

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders", $folderData);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'description',
                'icon',
                'color',
                'parent_id',
                'created_at',
                'updated_at',
            ],
        ]);

        $this->assertDatabaseHas('asset_folders', [
            'name' => 'Test Folder',
        ]);
    }

    #[Test]
    public function user_can_list_asset_folders()
    {
        // Create a few folders
        AssetFolder::factory()->count(3)->create();

        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders");

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'description',
                    'icon',
                    'color',
                ],
            ],
        ]);
    }

    #[Test]
    public function user_can_list_folders_by_parent()
    {
        // Create a parent folder
        $parentFolder = AssetFolder::factory()->create([
            'name' => 'Parent Folder',
        ]);

        // Create child folders
        AssetFolder::factory()->count(2)->create([
            'parent_id' => $parentFolder->id,
        ]);

        // Create other folders without parent
        AssetFolder::factory()->count(3)->create([]);

        // Get only the child folders
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders?parent_id={$parentFolder->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        // All results should have the parent folder id
        $data = $response->json('data');
        foreach ($data as $folder) {
            $this->assertEquals($parentFolder->id, $folder['parent_id']);
        }

        // Get only root folders
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders?parent_id=null");

        $response->assertStatus(200);
        $response->assertJsonCount(4, 'data'); // 3 root folders + the parent folder

        // All results should have null parent
        $data = $response->json('data');
        foreach ($data as $folder) {
            $this->assertNull($folder['parent_id']);
        }
    }

    #[Test]
    public function user_can_view_folder_details()
    {
        $folder = AssetFolder::factory()->create([
            'name' => 'Test Folder',
            'description' => 'Test description',
            'icon' => 'folder',
            'color' => '#4ade80',
        ]);

        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders/{$folder->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $folder->id,
                'name' => 'Test Folder',
                'description' => 'Test description',
                'icon' => 'folder',
                'color' => '#4ade80',
            ],
        ]);
    }

    #[Test]
    public function user_can_update_a_folder()
    {
        $folder = AssetFolder::factory()->create([
            'name' => 'Original Folder Name',
            'description' => 'Original description',
        ]);

        $updateData = [
            'name' => 'Updated Folder Name',
            'description' => 'Updated description',
            'icon' => 'image',
            'color' => '#f97316',
        ];

        $response = $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders/{$folder->id}", $updateData);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $folder->id,
                'name' => 'Updated Folder Name',
                'description' => 'Updated description',
                'icon' => 'image',
                'color' => '#f97316',
            ],
        ]);

        $this->assertDatabaseHas('asset_folders', [
            'id' => $folder->id,
            'name' => 'Updated Folder Name',
            'description' => 'Updated description',
        ]);
    }

    #[Test]
    public function user_can_create_nested_folders()
    {
        // Create a parent folder
        $parentFolder = AssetFolder::factory()->create([
            'name' => 'Parent Folder',
        ]);

        // Create a child folder
        $folderData = [
            'name' => 'Child Folder',
            'description' => 'This is a child folder',
            'icon' => 'folder',
            'color' => '#3b82f6',
            'storage_id' => $this->storage->id,
            'parent_id' => $parentFolder->id,
        ];

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders", $folderData);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => [
                'name' => 'Child Folder',
                'parent_id' => $parentFolder->id,
            ],
        ]);

        $this->assertDatabaseHas('asset_folders', [
            'name' => 'Child Folder',
            'parent_id' => $parentFolder->id,
        ]);
    }

    #[Test]
    public function user_cannot_create_folder_with_invalid_parent()
    {
        // Try to create a folder with a non-existent parent
        $folderData = [
            'name' => 'Invalid Parent Folder',
            'parent_id' => 'non-existent-id',
        ];

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders", $folderData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_id']);
    }

    #[Test]
    public function user_can_delete_an_empty_folder()
    {
        $folder = AssetFolder::factory()->create([]);

        $response = $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders/{$folder->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('asset_folders', [
            'id' => $folder->id,
        ]);
    }

    #[Test]
    public function user_cannot_delete_folder_with_children()
    {
        // Create a parent folder
        $parentFolder = AssetFolder::factory()->create([]);

        // Create a child folder
        AssetFolder::factory()->create([
            'parent_id' => $parentFolder->id,
        ]);

        $response = $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders/{$parentFolder->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('asset_folders', [
            'id' => $parentFolder->id,
        ]);
    }

    #[Test]
    public function user_cannot_delete_folder_with_assets()
    {
        // Create a folder
        $folder = AssetFolder::factory()->create([]);

        // Add an asset to the folder
        \App\Models\Space\Asset::factory()->create([
            'folder_id' => $folder->id,
        ]);

        $response = $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders/{$folder->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('asset_folders', [
            'id' => $folder->id,
        ]);
    }

    #[Test]
    public function user_can_search_folders_by_name()
    {
        // Create folders with specific names
        AssetFolder::factory()->create([
            'name' => 'Marketing Assets',
        ]);

        AssetFolder::factory()->create([
            'name' => 'Development Documents',
        ]);

        // Search for "Marketing"
        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/asset-folders?search=Marketing");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('Marketing Assets', $response->json('data.0.name'));
    }
}
