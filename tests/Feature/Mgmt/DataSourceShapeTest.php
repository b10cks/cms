<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class DataSourceShapeTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        $this->actingAs($this->owner);
    }

    protected function shape(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'name' => 'Title', 'required' => true],
            ['key' => 'count', 'type' => 'number'],
            ['key' => 'active', 'type' => 'boolean'],
            ['key' => 'category', 'type' => 'option', 'options' => [
                ['name' => 'News', 'value' => 'news'],
                ['name' => 'Blog', 'value' => 'blog'],
            ]],
        ];
    }

    protected function createShapedSource(): DataSource
    {
        return DataSource::factory()->create([
            'dimensions' => [
                ['key' => 'en', 'label' => 'English'],
                ['key' => 'de', 'label' => 'German'],
            ],
            'shape' => $this->shape(),
        ]);
    }

    protected function storeEntryRoute(DataSource $source): string
    {
        return route('mgmt.data-sources.entries.store', [
            'space' => $this->space->id,
            'data_source' => $source->id,
        ]);
    }

    #[Test]
    public function it_creates_a_data_source_with_a_shape()
    {
        $response = $this->postJson(route('mgmt.data-sources.store', ['space' => $this->space->id]), [
            'name' => 'Products',
            'slug' => 'products',
            'shape' => $this->shape(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.shape.0.key', 'title');
        $response->assertJsonPath('data.shape.0.type', 'text');
        $response->assertJsonPath('data.shape.3.options.1.value', 'blog');
    }

    #[Test]
    public function it_returns_null_shape_for_sources_without_one()
    {
        $response = $this->postJson(route('mgmt.data-sources.store', ['space' => $this->space->id]), [
            'name' => 'Plain',
            'slug' => 'plain',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.shape', null);
    }

    #[Test]
    public function it_rejects_invalid_shapes()
    {
        $route = route('mgmt.data-sources.store', ['space' => $this->space->id]);
        $base = ['name' => 'Products', 'slug' => 'products'];

        // Unknown field type
        $this->postJson($route, $base + ['shape' => [['key' => 'ref', 'type' => 'references']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shape.0.type');

        // Duplicate keys
        $this->postJson($route, $base + ['shape' => [
            ['key' => 'title', 'type' => 'text'],
            ['key' => 'title', 'type' => 'textarea'],
        ]])->assertStatus(422)->assertJsonValidationErrors('shape.0.key');

        // Option field without options
        $this->postJson($route, $base + ['shape' => [['key' => 'cat', 'type' => 'option']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shape.0.options');

        // Default not matching the field type
        $this->postJson($route, $base + ['shape' => [['key' => 'count', 'type' => 'number', 'default' => 'many']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shape.0.default');
    }

    #[Test]
    public function it_stores_and_returns_structured_entry_values()
    {
        $source = $this->createShapedSource();

        $response = $this->postJson($this->storeEntryRoute($source), [
            'key' => 'first',
            'value' => [
                'title' => 'Hello',
                'count' => 3,
                'active' => true,
                'category' => 'news',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.value.title', 'Hello');
        $response->assertJsonPath('data.value.category', 'news');

        $entry = DataEntry::where('data_source_id', $source->id)->where('key', 'first')->first();
        $this->assertIsString($entry->getRawOriginal('value'));
        $this->assertSame('Hello', json_decode($entry->getRawOriginal('value'), true)['title']);
    }

    #[Test]
    public function it_strips_unknown_value_keys()
    {
        $source = $this->createShapedSource();

        $response = $this->postJson($this->storeEntryRoute($source), [
            'key' => 'stripped',
            'value' => ['title' => 'Hello', 'unknown' => 'nope'],
        ]);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('unknown', $response->json('data.value'));
    }

    #[Test]
    public function it_validates_structured_entry_values()
    {
        $source = $this->createShapedSource();
        $route = $this->storeEntryRoute($source);

        // Plain string on a shaped source
        $this->postJson($route, ['key' => 'a', 'value' => 'plain string'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');

        // Missing required field
        $this->postJson($route, ['key' => 'b', 'value' => ['count' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value.title');

        // Wrong field type
        $this->postJson($route, ['key' => 'c', 'value' => ['title' => 'Hi', 'count' => 'many']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value.count');

        // Invalid option value
        $this->postJson($route, ['key' => 'd', 'value' => ['title' => 'Hi', 'category' => 'invalid']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value.category');
    }

    #[Test]
    public function dimension_overrides_do_not_require_required_fields()
    {
        $source = $this->createShapedSource();

        $response = $this->postJson($this->storeEntryRoute($source), [
            'key' => 'localized',
            'value' => ['title' => 'Hello'],
            'dimensions' => [
                'de' => ['title' => 'Hallo'],
                'en' => ['count' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.dimensions.de.title', 'Hallo');
        $response->assertJsonPath('data.dimensions.en.count', 2);
    }

    #[Test]
    public function it_updates_structured_entry_values()
    {
        $source = $this->createShapedSource();

        $this->postJson($this->storeEntryRoute($source), [
            'key' => 'updatable',
            'value' => ['title' => 'Before'],
        ])->assertStatus(201);

        $entry = DataEntry::where('data_source_id', $source->id)->where('key', 'updatable')->first();

        $response = $this->patchJson(route('mgmt.data-sources.entries.update', [
            'space' => $this->space->id,
            'data_source' => $source->id,
            'entry' => $entry->id,
        ]), [
            'value' => ['title' => 'After', 'count' => 7],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.value.title', 'After');
        $response->assertJsonPath('data.value.count', 7);
    }

    #[Test]
    public function it_returns_raw_strings_for_values_that_predate_the_shape()
    {
        $source = $this->createShapedSource();

        $entry = DataEntry::factory()->create([
            'data_source_id' => $source->id,
            'key' => 'legacy',
            'value' => 'plain legacy value',
        ]);

        $response = $this->getJson(route('mgmt.data-sources.entries.show', [
            'space' => $this->space->id,
            'data_source' => $source->id,
            'entry' => $entry->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.value', 'plain legacy value');
    }

    #[Test]
    public function shapeless_sources_keep_plain_string_values()
    {
        $source = DataSource::factory()->create();

        $response = $this->postJson($this->storeEntryRoute($source), [
            'key' => 'plain',
            'value' => 'just a string',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.value', 'just a string');

        // Structured values are rejected without a shape
        $this->postJson($this->storeEntryRoute($source), [
            'key' => 'structured',
            'value' => ['title' => 'nope'],
        ])->assertStatus(422)->assertJsonValidationErrors('value');
    }

    #[Test]
    public function ai_translation_is_rejected_for_shaped_sources()
    {
        $source = $this->createShapedSource();
        $source->update(['settings' => [
            'dimensions_translatable' => true,
            'default_dimension_locale' => 'en',
        ]]);

        $response = $this->postJson(route('mgmt.data-sources.entries.translate-missing-dimensions.stream', [
            'space' => $this->space->id,
            'data_source' => $source->id,
        ]), [
            'target_dimension' => 'de',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('target_dimension');
    }
}
