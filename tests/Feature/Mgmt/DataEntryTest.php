<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class DataEntryTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;
    use WithFaker;

    protected User $owner;

    protected User $admin;

    protected User $editor;

    protected User $viewer;

    protected Space $space;

    protected DataSource $dataSource;

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
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->assignSpaceRole($this->space, $this->admin, 'admin');
        $this->assignSpaceRole($this->space, $this->editor, 'editor');
        $this->assignSpaceRole($this->space, $this->viewer, 'viewer');

        $this->setUpSpaceTesting($this->space);

        // Create a data source for this space
        $this->dataSource = DataSource::factory()->create([
            'dimensions' => [
                ['key' => 'en', 'label' => 'English'],
                ['key' => 'fr', 'label' => 'French'],
                ['key' => 'de', 'label' => 'German'],
            ],
            'settings' => [
                'fallback_dimension' => 'en',
                'cache_ttl' => 3600,
            ],
        ]);
    }

    #[Test]
    public function owner_can_create_data_entry()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(
            route('mgmt.data-sources.entries.store', [
                'space' => $this->space->id,
                'data_source' => $this->dataSource->id,
            ]),
            [
                'key' => 'welcome_message',
                'value' => 'Welcome to our site',
                'dimensions' => [
                    'fr' => 'Bienvenue sur notre site',
                    'de' => 'Willkommen auf unserer Website',
                ],
                'is_active' => true,
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.key', 'welcome_message');
        $response->assertJsonPath('data.value', 'Welcome to our site');
        $response->assertJsonPath('data.dimensions.fr', 'Bienvenue sur notre site');

        $this->assertDatabaseHas('data_entries', [
            'data_source_id' => $this->dataSource->id,
            'key' => 'welcome_message',
            'value' => 'Welcome to our site',
        ]);
    }

    #[Test]
    public function admin_can_create_data_entry()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(
            route('mgmt.data-sources.entries.store', [
                'space' => $this->space->id,
                'data_source' => $this->dataSource->id,
            ]),
            [
                'key' => 'admin_message',
                'value' => 'Created by admin',
                'dimensions' => [
                    'en' => 'Created by admin',
                ],
            ]
        );

        $response->assertStatus(201);
    }

    #[Test]
    public function editor_can_create_data_entry()
    {
        $this->actingAs($this->editor);

        $response = $this->postJson(
            route('mgmt.data-sources.entries.store', [
                'space' => $this->space->id,
                'data_source' => $this->dataSource->id,
            ]),
            [
                'key' => 'editor_message',
                'value' => 'Created by editor',
                'dimensions' => [
                    'en' => 'Created by editor',
                ],
            ]
        );

        $response->assertStatus(201);
    }

    #[Test]
    public function viewer_cannot_create_data_entry()
    {
        $this->actingAs($this->viewer);

        $response = $this->postJson(
            route('mgmt.data-sources.entries.store', [
                'space' => $this->space->id,
                'data_source' => $this->dataSource->id,
            ]),
            [
                'key' => 'viewer_message',
                'value' => 'Created by viewer',
                'dimensions' => [
                    'en' => 'Created by viewer',
                ],
            ]
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function it_validates_data_entry_creation()
    {
        $this->actingAs($this->owner);

        // Test with missing key
        $response = $this->postJson(
            route('mgmt.data-sources.entries.store', [
                'space' => $this->space->id,
                'data_source' => $this->dataSource->id,
            ]),
            [
                'value' => 'Test value',
                'dimensions' => [
                    'en' => 'English value',
                ],
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('key');

        // Test with invalid dimension
        $response = $this->postJson(
            route('mgmt.data-sources.entries.store', [
                'space' => $this->space->id,
                'data_source' => $this->dataSource->id,
            ]),
            [
                'key' => 'test_key',
                'value' => 'Test value',
                'dimensions' => [
                    'es' => 'Spanish value', // 'es' is not in our data source dimensions
                ],
            ]
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function it_can_list_data_entries()
    {
        $this->actingAs($this->viewer);

        // Create some entries
        DataEntry::factory()->count(5)->create([
            'data_source_id' => $this->dataSource->id,
        ]);

        $response = $this->getJson(route('mgmt.data-sources.entries.index', [
            'space' => $this->space->id,
            'data_source' => $this->dataSource->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonCount(5, 'data');
    }

    #[Test]
    public function it_can_show_data_entry_details()
    {
        $this->actingAs($this->viewer);

        $entry = DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'key' => 'test_key',
            'value' => 'Test value',
            'dimensions' => [
                'fr' => 'Valeur de test',
                'de' => 'Testwert',
            ],
        ]);

        $response = $this->getJson(route('mgmt.data-sources.entries.show', [
            'space' => $this->space->id,
            'data_source' => $this->dataSource->id,
            'entry' => $entry->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.key', 'test_key');
        $response->assertJsonPath('data.value', 'Test value');
        $response->assertJsonPath('data.dimensions.fr', 'Valeur de test');
    }

    #[Test]
    public function owner_can_update_data_entry()
    {
        $this->actingAs($this->owner);

        $entry = DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'key' => 'update_test',
            'value' => 'Original value',
            'dimensions' => [
                'en' => 'Original English',
            ],
        ]);

        $response = $this->patchJson(route('mgmt.data-sources.entries.update', [
            'space' => $this->space->id,
            'data_source' => $this->dataSource->id,
            'entry' => $entry->id,
        ]), [
            'value' => 'Updated value',
            'dimensions' => [
                'en' => 'Updated English',
                'fr' => 'Updated French',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.value', 'Updated value');
        $response->assertJsonPath('data.dimensions.fr', 'Updated French');

        $this->assertDatabaseHas('data_entries', [
            'id' => $entry->id,
            'value' => 'Updated value',
        ]);
    }

    #[Test]
    public function editor_can_update_data_entry()
    {
        $this->actingAs($this->editor);

        $entry = DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'key' => 'editor_update_test',
            'value' => 'Original value',
        ]);

        $response = $this->patchJson(route('mgmt.data-sources.entries.update', [
            'space' => $this->space->id,
            'data_source' => $this->dataSource->id,
            'entry' => $entry->id,
        ]), [
            'value' => 'Updated by editor',
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function viewer_cannot_update_data_entry()
    {
        $this->actingAs($this->viewer);

        $entry = DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'key' => 'viewer_update_test',
            'value' => 'Original value',
        ]);

        $response = $this->patchJson(route('mgmt.data-sources.entries.update', [
            'space' => $this->space->id,
            'data_source' => $this->dataSource->id,
            'entry' => $entry->id,
        ]), [
            'value' => 'Updated by viewer',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function owner_can_delete_data_entry()
    {
        $this->actingAs($this->owner);

        $entry = DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
        ]);

        $response = $this->deleteJson(route('mgmt.data-sources.entries.destroy', [
            'space' => $this->space->id,
            'data_source' => $this->dataSource->id,
            'entry' => $entry->id,
        ]));

        $response->assertStatus(204);
        $this->assertDatabaseMissing('data_entries', ['id' => $entry->id]);
    }

    #[Test]
    public function viewer_cannot_delete_data_entry()
    {
        $this->actingAs($this->viewer);

        $entry = DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
        ]);

        $response = $this->deleteJson(route('mgmt.data-sources.entries.destroy', [
            'space' => $this->space->id,
            'data_source' => $this->dataSource->id,
            'entry' => $entry->id,
        ]));

        $response->assertStatus(403);
    }

    #[Test]
    public function it_enforces_unique_key_per_data_source()
    {
        $this->actingAs($this->owner);

        // Create an entry
        DataEntry::factory()->create([
            'data_source_id' => $this->dataSource->id,
            'key' => 'duplicate-key',
        ]);

        // Try to create another with the same key
        $response = $this->postJson(
            route('mgmt.data-sources.entries.store', [
                'space' => $this->space->id,
                'data_source' => $this->dataSource->id,
            ]),
            [
                'key' => 'duplicate-key',
                'value' => 'Another value',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('key');
    }
}
