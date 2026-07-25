<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentSerial;
use App\Models\User;
use App\Services\Content\Serial\SerialAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The configurable half of the serial field: uniqueness modes, move behaviour,
 * editable overrides and the reset dimensions.
 */
#[CoversClass(SerialAllocator::class)]
class ContentSerialOptionsTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->user, 'owner');
        Sanctum::actingAs($this->user);
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function an_editable_serial_accepts_a_value_from_the_editor(): void
    {
        $block = $this->createBlock('product', [
            'sku' => ['type' => 'serial', 'format' => '{counter}', 'editable' => true],
        ]);

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Widget',
            'slug' => 'widget',
            'block_id' => $block->id,
            'content' => ['sku' => 'CUSTOM-1'],
        ])->assertCreated();

        $this->assertSame('CUSTOM-1', $response->json('data.content.sku'));
        $this->assertDatabaseHas('content_serials', ['field_key' => 'sku', 'value' => 'CUSTOM-1']);
    }

    #[Test]
    public function an_editable_serial_still_has_to_be_unique(): void
    {
        $block = $this->createBlock('product', [
            'sku' => ['type' => 'serial', 'format' => '{counter}', 'editable' => true, 'scope' => ['block']],
        ]);

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Widget',
            'slug' => 'widget',
            'block_id' => $block->id,
            'content' => ['sku' => 'CUSTOM-1'],
        ])->assertCreated();

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Gadget',
            'slug' => 'gadget',
            'block_id' => $block->id,
            'content' => ['sku' => 'CUSTOM-1'],
        ])->assertStatus(422)->assertJsonValidationErrors('content.sku');
    }

    #[Test]
    public function a_reallocating_serial_is_renumbered_when_it_moves(): void
    {
        $folder = $this->createBlock('folder', []);
        $item = $this->createBlock('item', [
            'ref' => [
                'type' => 'serial',
                'format' => '{counter}',
                'scope' => ['block', 'parent'],
                'on_move' => 'reallocate',
            ],
        ]);

        $a = $this->createContent($folder, 'A');
        $b = $this->createContent($folder, 'B');

        $this->createContent($item, 'First', $b);
        $moved = $this->createContent($item, 'Moved', $a);

        $this->assertSame('1', $this->valueOf($moved, 'ref'));

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/{$moved->id}/move", [
            'parent_id' => $b->id,
        ])->assertOk();

        // B already had one item, so the new scope hands out 2.
        $this->assertSame('2', $this->valueOf($moved->fresh(), 'ref'));
        $this->assertSame(
            1,
            ContentSerial::query()->where('content_id', $moved->id)->count(),
            'Reallocation must replace the reservation, not add a second one.',
        );
    }

    #[Test]
    public function a_year_scope_restarts_the_counter(): void
    {
        $block = $this->createBlock('invoice', [
            'no' => [
                'type' => 'serial',
                'format' => '{date:Y}-{counter:4}',
                'scope' => ['block', 'year'],
            ],
        ]);

        Carbon::setTestNow('2025-12-31 12:00:00');
        $first = $this->createContent($block, 'Last of 2025');

        Carbon::setTestNow('2026-01-01 12:00:00');
        $second = $this->createContent($block, 'First of 2026');

        $this->assertSame('2025-0001', $this->valueOf($first, 'no'));
        $this->assertSame('2026-0001', $this->valueOf($second, 'no'), 'A new year restarts the counter.');

        Carbon::setTestNow();
    }

    #[Test]
    public function space_wide_uniqueness_rejects_a_format_that_cannot_stay_distinct(): void
    {
        $houses = $this->createBlock('house', [
            'ref' => ['type' => 'serial', 'format' => '{counter}', 'scope' => ['block'], 'unique' => 'space'],
        ]);
        $cars = $this->createBlock('car', [
            'ref' => ['type' => 'serial', 'format' => '{counter}', 'scope' => ['block'], 'unique' => 'space'],
        ]);

        $this->createContent($houses, 'House one');

        // Both formats render "1" in their own block. The allocator refuses to
        // silently skip to 2 — the format is what has to distinguish them.
        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Car one',
            'slug' => 'car-one',
            'block_id' => $cars->id,
        ])->assertStatus(422)->assertJsonValidationErrors('content.ref');
    }

    #[Test]
    public function uniqueness_can_be_switched_off(): void
    {
        $houses = $this->createBlock('house', [
            'ref' => ['type' => 'serial', 'format' => '{counter}', 'scope' => ['block'], 'unique' => 'none'],
        ]);
        $cars = $this->createBlock('car', [
            'ref' => ['type' => 'serial', 'format' => '{counter}', 'scope' => ['block'], 'unique' => 'none'],
        ]);

        $house = $this->createContent($houses, 'House one');
        $car = $this->createContent($cars, 'Car one');

        $this->assertSame('1', $this->valueOf($house, 'ref'));
        $this->assertSame('1', $this->valueOf($car, 'ref'));
    }

    #[Test]
    public function a_field_token_reads_another_field_on_the_same_entry(): void
    {
        $block = $this->createBlock('ticket', [
            'region' => ['type' => 'text'],
            'ref' => ['type' => 'serial', 'format' => '{field:region}-{counter:2}', 'scope' => ['block']],
        ]);

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => 'Ticket',
            'slug' => 'ticket',
            'block_id' => $block->id,
            'content' => ['region' => 'EU'],
        ])->assertCreated();

        $this->assertSame('EU-01', $response->json('data.content.ref'));
    }

    #[Test]
    public function a_format_referencing_an_unknown_field_is_rejected_at_schema_save(): void
    {
        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/blocks", [
            'name' => 'Broken',
            'slug' => 'brokenfield',
            'type' => 'root',
            'schema' => [
                'ref' => ['type' => 'serial', 'format' => '{field:nope}-{counter}'],
            ],
            'editor' => [['header' => 'General', 'items' => ['ref']]],
        ])->assertStatus(422)->assertJsonValidationErrors('schema.ref.format');
    }

    #[Test]
    public function a_serial_is_never_translatable(): void
    {
        // A serial identifies the entry, not one of its language variants, so
        // the flag is normalized away rather than honoured.
        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/blocks", [
            'name' => 'Numbered',
            'slug' => 'numbered',
            'type' => 'root',
            'schema' => [
                'ref' => ['type' => 'serial', 'format' => '{counter}', 'translatable' => true],
            ],
            'editor' => [['header' => 'General', 'items' => ['ref']]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.schema.ref.translatable', false);
    }

    #[Test]
    public function restoring_under_preserve_gives_back_the_original_identifier(): void
    {
        $block = $this->createBlock('item', [
            'ref' => ['type' => 'serial', 'format' => '{counter}', 'scope' => ['block']],
        ]);

        $content = $this->createContent($block, 'First');
        $this->createContent($block, 'Second');

        $content->delete();
        $content->restore();

        $this->assertSame('1', $this->valueOf($content->fresh(), 'ref'));
        $this->assertDatabaseHas('content_serials', ['content_id' => $content->id, 'number' => 1]);
    }

    #[Test]
    public function restoring_under_reuse_renumbers_when_the_slot_was_taken(): void
    {
        $settings = $this->space->settings;
        $settings->apply(['serial_gaps' => 'reuse']);
        $this->space->settings = $settings;
        $this->space->save();
        $this->space->refresh();

        $block = $this->createBlock('item', [
            'ref' => ['type' => 'serial', 'format' => '{counter}', 'scope' => ['block']],
        ]);

        $content = $this->createContent($block, 'First');
        $content->delete();

        // Someone else takes the freed number while the entry is in the trash.
        $usurper = $this->createContent($block, 'Second');
        $this->assertSame('1', $this->valueOf($usurper, 'ref'));

        $content->restore();

        // The documented cost of gap reuse: a restored identifier can change.
        $this->assertSame('2', $this->valueOf($content->fresh(), 'ref'));
        $this->assertSame(2, ContentSerial::query()->count());
    }

    #[Test]
    public function restoring_under_reuse_keeps_the_identifier_when_the_slot_is_free(): void
    {
        $settings = $this->space->settings;
        $settings->apply(['serial_gaps' => 'reuse']);
        $this->space->settings = $settings;
        $this->space->save();
        $this->space->refresh();

        $block = $this->createBlock('item', [
            'ref' => ['type' => 'serial', 'format' => '{counter}', 'scope' => ['block']],
        ]);

        $content = $this->createContent($block, 'First');
        $content->delete();
        $content->restore();

        $this->assertSame('1', $this->valueOf($content->fresh(), 'ref'));
    }

    #[Test]
    public function a_non_member_cannot_preview_serials(): void
    {
        $block = $this->createBlock('house', [
            'ref' => ['type' => 'serial', 'format' => '{counter}'],
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents/serial-preview", [
            'block_id' => $block->id,
        ])->assertForbidden();
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
