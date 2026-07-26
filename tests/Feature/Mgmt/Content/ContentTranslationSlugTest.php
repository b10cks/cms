<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentSerial;
use App\Models\User;
use App\Services\Content\Serial\ContentSlugComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The slug and serial rules for translated content: a translation follows the
 * block's slug pattern in its own language, but always carries the canonical
 * entry's serial rather than drawing one of its own.
 */
#[CoversClass(ContentSlugComposer::class)]
class ContentTranslationSlugTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected Block $productBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->user, 'owner');
        Sanctum::actingAs($this->user);
        $this->setUpSpaceTesting($this->space);

        $settings = $this->space->settings;
        $settings->apply([
            'default_language' => 'en',
            'languages' => [
                ['code' => 'de', 'name' => 'German'],
            ],
        ]);
        $this->space->settings = $settings;
        $this->space->save();
        $this->space->refresh();

        $this->productBlock = $this->createBlock('product', [
            'sku' => [
                'type' => 'serial',
                'name' => 'SKU',
                'format' => 'P-{counter:3}',
                'scope' => ['block'],
            ],
        ], ['slug_pattern' => '{field:sku}-{field:name}']);
    }

    #[Test]
    public function a_translation_without_a_slug_follows_the_pattern_with_the_canonical_serial(): void
    {
        $canonical = $this->createContent($this->productBlock, 'Wooden Chair');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Holzstuhl',
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
        ])->assertCreated();

        // The canonical serial, the translated name — and no second ledger row.
        $this->assertSame('p-001-holzstuhl', $response->json('data.slug'));
        $this->assertSame('P-001', $response->json('data.content.sku'));
        $this->assertSame(1, ContentSerial::query()->where('field_key', 'sku')->count());
    }

    #[Test]
    public function a_translation_without_a_slug_falls_back_to_its_translated_name(): void
    {
        $block = $this->createBlock('simple', []);
        $canonical = $this->createContent($block, 'Wooden Chair');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Holzstuhl',
            'block_id' => $block->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
        ])->assertCreated();

        $this->assertSame('holzstuhl', $response->json('data.slug'));
    }

    #[Test]
    public function a_language_token_renders_the_translations_language(): void
    {
        $this->productBlock->settings = ['slug_pattern' => '{lang}-{field:sku}'];
        $this->productBlock->save();

        $canonical = $this->createContent($this->productBlock, 'Wooden Chair');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Holzstuhl',
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
        ])->assertCreated();

        $this->assertSame('de-p-001', $response->json('data.slug'));
    }

    #[Test]
    public function a_generated_translation_slug_is_unique_among_its_languages_siblings(): void
    {
        $block = $this->createBlock('simple', []);
        $first = $this->createContent($block, 'First');
        $second = $this->createContent($block, 'Second');

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Stuhl',
            'block_id' => $block->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $first->id,
        ])->assertCreated()->assertJsonPath('data.slug', 'stuhl');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Stuhl',
            'block_id' => $block->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $second->id,
        ])->assertCreated();

        $this->assertSame('stuhl-2', $response->json('data.slug'));
    }

    #[Test]
    public function the_family_create_endpoint_accepts_translations_without_a_slug(): void
    {
        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Wooden Chair',
            'block_id' => $this->productBlock->id,
            'translations' => [
                [
                    'name' => 'Holzstuhl',
                    'language_iso' => 'de',
                ],
            ],
        ])->assertCreated();

        $translation = Content::query()
            ->where('i18n_parent_id', $response->json('data.id'))
            ->firstOrFail();

        $this->assertSame('p-001-holzstuhl', $translation->slug);
        $this->assertSame(1, ContentSerial::query()->where('field_key', 'sku')->count());
    }

    #[Test]
    public function the_preview_reports_the_canonical_serial_for_a_translation_without_allocating(): void
    {
        $canonical = $this->createContent($this->productBlock, 'Wooden Chair');

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'name' => 'Holzstuhl',
            'i18n_parent_id' => $canonical->id,
        ])
            ->assertOk()
            // The retained value, not a peek at the next counter slot.
            ->assertJsonPath('fields.sku.value', 'P-001')
            ->assertJsonPath('fields.sku.preview', false)
            ->assertJsonPath('slug_preview', 'p-001-holzstuhl');

        $this->assertSame(
            1,
            ContentSerial::query()->where('field_key', 'sku')->count(),
            'Previewing a translation must not allocate.',
        );
    }

    #[Test]
    public function the_translation_preview_matches_what_creating_the_translation_produces(): void
    {
        $canonical = $this->createContent($this->productBlock, 'Wooden Chair');

        $preview = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'name' => 'Holzstuhl',
            'i18n_parent_id' => $canonical->id,
        ])->assertOk();

        $created = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Holzstuhl',
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
        ])->assertCreated();

        $this->assertSame($preview->json('slug_preview'), $created->json('data.slug'));
        $this->assertSame($preview->json('fields.sku.value'), $created->json('data.content.sku'));
    }

    #[Test]
    public function the_preview_omits_a_serial_the_canonical_does_not_carry_yet(): void
    {
        // The serial field is added after the canonical was created, so the
        // canonical has no value — and the translation must not preview one.
        $block = $this->createBlock('late', []);
        $canonical = $this->createContent($block, 'Wooden Chair');

        $block->schema = [
            'code' => [
                'type' => 'serial',
                'name' => 'Code',
                'format' => '{counter}',
                'scope' => ['block'],
            ],
        ];
        $block->editor = [['header' => 'General', 'items' => ['code']]];
        $block->save();

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $block->id,
            'language_iso' => 'de',
            'name' => 'Holzstuhl',
            'i18n_parent_id' => $canonical->id,
        ])
            ->assertOk()
            ->assertJsonMissingPath('fields.code');

        $this->assertSame(0, ContentSerial::query()->where('field_key', 'code')->count());
    }

    #[Test]
    public function an_existing_translation_can_regenerate_its_slug_without_colliding_with_itself(): void
    {
        $canonical = $this->createContent($this->productBlock, 'Wooden Chair');

        $translation = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Holzstuhl',
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
        ])->assertCreated();

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'name' => 'Holzstuhl',
            'i18n_parent_id' => $canonical->id,
            'except_content_id' => $translation->json('data.id'),
        ])
            ->assertOk()
            // Without except_content_id this would be suffixed `-2` because the
            // translation itself already owns the slug.
            ->assertJsonPath('slug_preview', 'p-001-holzstuhl');
    }

    #[Test]
    public function an_explicit_translation_slug_still_beats_the_pattern(): void
    {
        $canonical = $this->createContent($this->productBlock, 'Wooden Chair');

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Holzstuhl',
            'slug' => 'mein-stuhl',
            'block_id' => $this->productBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
        ])->assertCreated();

        $this->assertSame('mein-stuhl', $response->json('data.slug'));
        $this->assertSame('P-001', $response->json('data.content.sku'));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $settings
     */
    protected function createBlock(string $slug, array $schema, array $settings = []): Block
    {
        $block = new Block([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => 'root',
            'schema' => $schema,
            'editor' => [['header' => 'General', 'items' => array_keys($schema)]],
            'settings' => $settings,
        ]);
        $block->save();

        return $block->fresh();
    }

    protected function createContent(Block $block, string $name): Content
    {
        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => $name,
            'block_id' => $block->id,
        ]);

        $response->assertCreated();

        return Content::query()->findOrFail($response->json('data.id'));
    }
}
