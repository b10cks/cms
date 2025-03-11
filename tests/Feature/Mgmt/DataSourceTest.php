<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Space\DataSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class DataSourceTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $owner;
    protected User $admin;
    protected User $editor;
    protected User $viewer;
    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users with different roles
        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->editor = User::factory()->create();
        $this->viewer = User::factory()->create();

        // Create a space and assign users with different roles
        $this->space = Space::factory()->create();
        $this->space->users()->attach($this->owner, ['role' => 'owner']);
        $this->space->users()->attach($this->admin, ['role' => 'admin']);
        $this->space->users()->attach($this->editor, ['role' => 'editor']);
        $this->space->users()->attach($this->viewer, ['role' => 'viewer']);

        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function owner_can_create_data_source()
    {
        $this->actingAs($this->owner);

        $dimensions = [
            'en' => 'English',
            'fr' => 'French',
            'de' => 'German'
        ];

        $response = $this->postJson(
            route('mgmt.data-sources.store', $this->space->id),
            [
                'name' => 'Test Data Source',
                'slug' => 'test-data-source',
                'description' => 'This is a test data source',
                'dimensions' => $dimensions,
                'settings' => [
                    'fallback_dimension' => 'en',
                    'cache_ttl' => 3600
                ],
                'is_active' => true
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Test Data Source');
        $response->assertJsonPath('data.slug', 'test-data-source');
        $response->assertJsonPath('data.dimensions.en', 'English');

        $this->assertDatabaseHas('data_sources', [
            'name' => 'Test Data Source',
            'slug' => 'test-data-source',
        ]);
    }

    #[Test]
    public function admin_can_create_data_source()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(
            route('mgmt.data-sources.store', $this->space->id),
            [
                'name' => 'Admin Data Source',
                'slug' => 'admin-data-source',
                'description' => 'Created by admin',
                'dimensions' => ['en' => 'English'],
                'is_active' => true
            ]
        );

        $response->assertStatus(201);
    }

    #[Test]
    public function editor_cannot_create_data_source()
    {
        $this->actingAs($this->editor);

        $response = $this->postJson(
            route('mgmt.data-sources.store', $this->space->id),
            [
                'name' => 'Editor Data Source',
                'slug' => 'editor-data-source',
                'description' => 'Created by editor',
                'dimensions' => ['en' => 'English'],
                'is_active' => true
            ]
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function viewer_cannot_create_data_source()
    {
        $this->actingAs($this->viewer);

        $response = $this->postJson(
            route('mgmt.data-sources.store', $this->space->id),
            [
                'name' => 'Viewer Data Source',
                'slug' => 'viewer-data-source',
                'description' => 'Created by viewer',
                'dimensions' => ['en' => 'English'],
                'is_active' => true
            ]
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function it_validates_data_source_creation()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.data-sources.store', $this->space->id),
            [
                'name' => '', // Empty name should fail
                'slug' => 'test-data-source',
                'dimensions' => [] // Empty dimensions should fail
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'dimensions']);
    }

    #[Test]
    public function it_can_list_data_sources()
    {
        $this->actingAs($this->viewer);

        DataSource::factory()->count(3)->create();

        $response = $this->getJson(route('mgmt.data-sources.index', $this->space->id));

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_can_show_data_source_details()
    {
        $this->actingAs($this->viewer);

        $dataSource = DataSource::factory()->create([
            'name' => 'Test Data Source',
            'dimensions' => ['en' => 'English', 'fr' => 'French']
        ]);

        $response = $this->getJson(route('mgmt.data-sources.show', [
            'space' => $this->space->id,
            'data_source' => $dataSource->id
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Test Data Source');
        $response->assertJsonPath('data.dimensions.en', 'English');
    }

    #[Test]
    public function owner_can_update_data_source()
    {
        $this->actingAs($this->owner);

        $dataSource = DataSource::factory()->create([
            'name' => 'Original Name',
            'dimensions' => ['en' => 'English']
        ]);

        $response = $this->patchJson(route('mgmt.data-sources.update', [
            'space' => $this->space->id,
            'data_source' => $dataSource->id
        ]), [
            'name' => 'Updated Name',
            'dimensions' => ['en' => 'English', 'fr' => 'French'],
            'settings' => ['fallback_dimension' => 'en']
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Updated Name');
        $response->assertJsonPath('data.dimensions.fr', 'French');

        $this->assertDatabaseHas('data_sources', [
            'id' => $dataSource->id,
            'name' => 'Updated Name'
        ]);
    }

    #[Test]
    public function editor_cannot_update_data_source()
    {
        $this->actingAs($this->editor);

        $dataSource = DataSource::factory()->create([
            'name' => 'Original Name'
        ]);

        $response = $this->patchJson(route('mgmt.data-sources.update', [
            'space' => $this->space->id,
            'data_source' => $dataSource->id
        ]), [
            'name' => 'Updated By Editor'
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function owner_can_delete_data_source()
    {
        $this->actingAs($this->owner);

        $dataSource = DataSource::factory()->create();

        $response = $this->deleteJson(route('mgmt.data-sources.destroy', [
            'space' => $this->space->id,
            'data_source' => $dataSource->id
        ]));

        $response->assertStatus(204);
        $this->assertDatabaseMissing('data_sources', ['id' => $dataSource->id]);
    }

    #[Test]
    public function editor_cannot_delete_data_source()
    {
        $this->actingAs($this->editor);

        $dataSource = DataSource::factory()->create();

        $response = $this->deleteJson(route('mgmt.data-sources.destroy', [
            'space' => $this->space->id,
            'data_source' => $dataSource->id
        ]));

        $response->assertStatus(403);
    }

    #[Test]
    public function it_enforces_unique_slug()
    {
        $this->actingAs($this->owner);

        // Create a data source
        DataSource::factory()->create([
            'slug' => 'duplicate-slug'
        ]);

        // Try to create another with the same slug
        $response = $this->postJson(
            route('mgmt.data-sources.store', $this->space->id),
            [
                'name' => 'Another Source',
                'slug' => 'duplicate-slug',
                'dimensions' => ['en' => 'English']
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');
    }
}
