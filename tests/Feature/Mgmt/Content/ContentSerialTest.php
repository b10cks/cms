<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentSerial;
use App\Models\User;
use App\Services\Content\Serial\ContentSerialAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(ContentSerialAssigner::class)]
class ContentSerialTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected Block $categoryBlock;

    protected Block $houseBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->user, 'owner');
        Sanctum::actingAs($this->user);
        $this->setUpSpaceTesting($this->space);

        $this->applySpaceSettings([
            'default_language' => 'en',
            'languages' => [
                ['code' => 'de', 'name' => 'German'],
            ],
        ]);

        $this->categoryBlock = $this->createBlock('category', [
            'house_no' => [
                'type' => 'serial',
                'name' => 'Category number',
                'format' => '{counter}',
                'scope' => ['block'],
            ],
        ]);

        $this->houseBlock = $this->createBlock('house', [
            'number' => [
                'type' => 'serial',
                'name' => 'House number',
                'format' => '{ancestor:house_no}-{counter:3}',
                'scope' => ['block', 'parent'],
            ],
        ]);
    }

    #[Test]
    public function it_numbers_children_per_parent_with_the_parent_prefix(): void
    {
        $holzhaus = $this->createContent($this->categoryBlock, 'Holzhaus');
        $business = $this->createContent($this->categoryBlock, 'Business');

        $this->assertSame('1', $this->valueOf($holzhaus, 'house_no'));
        $this->assertSame('2', $this->valueOf($business, 'house_no'));

        $chalet = $this->createContent($this->houseBlock, 'Chalet Alpin', $holzhaus);
        $huette = $this->createContent($this->houseBlock, 'Berghuette', $holzhaus);
        $office = $this->createContent($this->houseBlock, 'Office Park', $business);

        $this->assertSame('1-001', $this->valueOf($chalet, 'number'));
        $this->assertSame('1-002', $this->valueOf($huette, 'number'));
        $this->assertSame('2-001', $this->valueOf($office, 'number'));
    }

    #[Test]
    public function the_ancestor_token_skips_intermediate_entries_without_a_value(): void
    {
        $holzhaus = $this->createContent($this->categoryBlock, 'Holzhaus');
        $folder = $this->createContent($this->houseBlock, 'Archive', $holzhaus);
        $nested = $this->createContent($this->houseBlock, 'Nested', $folder);

        // The folder itself is a house and carries 1-001; the nested entry sits
        // under it, so `{ancestor:house_no}` has to walk past it to the category.
        $this->assertSame('1-001', $this->valueOf($folder, 'number'));
        $this->assertSame('1-001', $this->valueOf($nested, 'number'));
    }

    #[Test]
    public function a_serial_value_posted_by_a_client_is_ignored(): void
    {
        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Forged',
            'slug' => 'forged',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
            'content' => ['number' => 'HACKED'],
        ])->assertCreated();

        $this->assertSame('1-001', $response->json('data.content.number'));
    }

    #[Test]
    public function a_serial_cannot_be_overwritten_on_update(): void
    {
        $category = $this->createContent($this->categoryBlock, 'Holzhaus');
        $house = $this->createContent($this->houseBlock, 'Chalet', $category);

        $this->putJson("/mgmt/v1/spaces/{$this->space->id}/contents/{$house->id}", [
            'name' => 'Chalet',
            'content' => ['number' => 'CHANGED'],
        ])->assertOk();

        $this->assertSame('1-001', $this->valueOf($house->fresh(), 'number'));
    }

    #[Test]
    public function translations_share_the_canonical_serial(): void
    {
        $category = $this->createContent($this->categoryBlock, 'Holzhaus');
        $house = $this->createContent($this->houseBlock, 'Chalet', $category);

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Chalet DE',
            'slug' => 'chalet-de',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $house->id,
        ])->assertCreated();

        $this->assertSame('1-001', $response->json('data.content.number'));
        $this->assertSame(
            1,
            ContentSerial::query()->where('field_key', 'number')->count(),
            'A translation must not draw its own number.',
        );
    }

    #[Test]
    public function deleting_an_entry_burns_its_number_by_default(): void
    {
        $category = $this->createContent($this->categoryBlock, 'Holzhaus');
        $first = $this->createContent($this->houseBlock, 'First', $category);
        $this->createContent($this->houseBlock, 'Second', $category);

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/contents/{$first->id}")->assertNoContent();

        $third = $this->createContent($this->houseBlock, 'Third', $category);

        $this->assertSame('1-003', $this->valueOf($third, 'number'));
    }

    #[Test]
    public function a_space_can_opt_into_reusing_gaps(): void
    {
        $this->applySpaceSettings(['serial_gaps' => 'reuse']);

        $category = $this->createContent($this->categoryBlock, 'Holzhaus');
        $first = $this->createContent($this->houseBlock, 'First', $category);
        $this->createContent($this->houseBlock, 'Second', $category);

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/contents/{$first->id}")->assertNoContent();

        $third = $this->createContent($this->houseBlock, 'Third', $category);

        $this->assertSame('1-001', $this->valueOf($third, 'number'), 'The freed number should be handed out again.');
    }

    #[Test]
    public function the_preview_endpoint_reports_the_next_value_without_reserving_it(): void
    {
        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])
            ->assertOk()
            ->assertJsonPath('fields.number.value', '1-001')
            ->assertJsonPath('fields.number.preview', true);

        $this->assertSame(
            0,
            ContentSerial::query()->where('field_key', 'number')->count(),
            'Previewing must not allocate.',
        );
    }

    #[Test]
    public function a_serial_survives_a_move_by_default(): void
    {
        $holzhaus = $this->createContent($this->categoryBlock, 'Holzhaus');
        $business = $this->createContent($this->categoryBlock, 'Business');
        $house = $this->createContent($this->houseBlock, 'Chalet', $holzhaus);

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/{$house->id}/move", [
            'parent_id' => $business->id,
        ])->assertOk();

        $this->assertSame('1-001', $this->valueOf($house->fresh(), 'number'));
    }

    #[Test]
    public function a_block_schema_rejects_a_format_without_a_counter(): void
    {
        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/blocks", [
            'name' => 'Broken',
            'slug' => 'broken',
            'type' => 'nestable',
            'schema' => [
                'code' => ['type' => 'serial', 'format' => '{parent:name}'],
            ],
            'editor' => [['header' => 'General', 'items' => ['code']]],
        ])->assertStatus(422)->assertJsonValidationErrors('schema.code.format');
    }

    #[Test]
    public function a_block_schema_rejects_an_unknown_token(): void
    {
        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/blocks", [
            'name' => 'Broken',
            'slug' => 'brokentwo',
            'type' => 'nestable',
            'schema' => [
                'code' => ['type' => 'serial', 'format' => '{nope}-{counter}'],
            ],
            'editor' => [['header' => 'General', 'items' => ['code']]],
        ])->assertStatus(422)->assertJsonValidationErrors('schema.code.format');
    }

    #[Test]
    public function a_block_slug_pattern_seeds_the_slug(): void
    {
        $this->houseBlock->settings = ['slug_pattern' => '{field:number}-{field:name}'];
        $this->houseBlock->save();

        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Chalet Alpin',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])->assertCreated();

        $this->assertSame('1-001-chalet-alpin', $response->json('data.slug'));
    }

    #[Test]
    public function the_content_tree_create_path_allocates_and_applies_the_pattern(): void
    {
        $this->houseBlock->settings = ['slug_pattern' => '{field:number}-{field:name}'];
        $this->houseBlock->save();

        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/tree-operations", [
            'operations' => [
                [
                    'type' => 'create',
                    'temp_id' => 'tmp-1',
                    'parent_id' => $category->id,
                    'block_id' => $this->houseBlock->id,
                    'name' => 'Chalet Alpin',
                ],
            ],
        ])->assertOk();

        $created = Content::query()
            ->where('block_id', $this->houseBlock->id)
            ->where('name', 'Chalet Alpin')
            ->firstOrFail();

        $this->assertSame('1-001', $this->valueOf($created, 'number'));
        $this->assertSame('1-001-chalet-alpin', $created->slug);
    }

    #[Test]
    public function an_explicit_slug_always_beats_the_pattern(): void
    {
        $this->houseBlock->settings = ['slug_pattern' => '{field:number}-{field:name}'];
        $this->houseBlock->save();

        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Chalet Alpin',
            'slug' => 'my-own-slug',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])->assertCreated();

        $this->assertSame('my-own-slug', $response->json('data.slug'));
        // The serial is still allocated — only the slug was the editor's call.
        $this->assertSame('1-001', $response->json('data.content.number'));
    }

    #[Test]
    public function a_generated_slug_is_suffixed_when_a_sibling_already_owns_it(): void
    {
        $this->houseBlock->settings = ['slug_pattern' => '{field:name}'];
        $this->houseBlock->save();

        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $first = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Chalet',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])->assertCreated();

        $second = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Chalet',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])->assertCreated();

        $this->assertSame('chalet', $first->json('data.slug'));
        $this->assertSame('chalet-2', $second->json('data.slug'));
    }

    #[Test]
    public function the_preview_reports_the_slug_the_entry_would_get(): void
    {
        $this->houseBlock->settings = ['slug_pattern' => '{field:number}-{field:name}'];
        $this->houseBlock->save();

        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
            'name' => 'Chalet Alpin',
        ])
            ->assertOk()
            ->assertJsonPath('slug_pattern', '{field:number}-{field:name}')
            ->assertJsonPath('slug_preview', '1-001-chalet-alpin');
    }

    #[Test]
    public function the_preview_matches_what_creating_the_entry_actually_produces(): void
    {
        $this->houseBlock->settings = ['slug_pattern' => '{field:number}-{field:name}'];
        $this->houseBlock->save();

        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $preview = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
            'name' => 'Chalet Alpin',
        ])->assertOk();

        $created = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Chalet Alpin',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])->assertCreated();

        // The whole point of routing the preview through the same composer.
        $this->assertSame($preview->json('slug_preview'), $created->json('data.slug'));
        $this->assertSame($preview->json('fields.number.value'), $created->json('data.content.number'));
    }

    #[Test]
    public function the_preview_omits_the_slug_until_a_name_is_typed(): void
    {
        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])
            ->assertOk()
            ->assertJsonPath('slug_preview', null)
            ->assertJsonPath('slug_pattern', null);
    }

    #[Test]
    public function blocks_without_a_slug_pattern_keep_the_previous_behaviour(): void
    {
        $category = $this->createContent($this->categoryBlock, 'Holzhaus');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Chalet Alpin',
            'block_id' => $this->houseBlock->id,
            'parent_id' => $category->id,
        ])->assertCreated();

        $this->assertSame('chalet-alpin', $response->json('data.slug'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function applySpaceSettings(array $values): void
    {
        $settings = $this->space->settings;
        $settings->apply($values);
        $this->space->settings = $settings;
        $this->space->save();
        $this->space->refresh();
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function createBlock(string $slug, array $schema): Block
    {
        $block = new Block([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => 'root',
            'schema' => $schema,
            'editor' => [['header' => 'General', 'items' => array_keys($schema)]],
        ]);
        $block->save();

        return $block->fresh();
    }

    protected function createContent(Block $block, string $name, ?Content $parent = null): Content
    {
        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", array_filter([
            'name' => $name,
            'slug' => \Str::slug($name),
            'block_id' => $block->id,
            'parent_id' => $parent?->id,
        ]));

        $response->assertCreated();

        return Content::query()->findOrFail($response->json('data.id'));
    }

    protected function valueOf(Content $content, string $key): mixed
    {
        return $content->getCurrentContent()[$key] ?? null;
    }
}
