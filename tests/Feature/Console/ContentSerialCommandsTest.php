<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AssignContentSerialsCommand;
use App\Console\Commands\ReissueContentSerialsCommand;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(AssignContentSerialsCommand::class)]
#[CoversClass(ReissueContentSerialsCommand::class)]
class ContentSerialCommandsTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected Block $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->user, 'owner');
        Sanctum::actingAs($this->user);
        $this->setUpSpaceTesting($this->space);

        // Created without the serial field, so entries exist before the field does.
        $this->block = $this->createBlock(['title' => ['type' => 'text']]);
    }

    #[Test]
    public function it_backfills_entries_that_predate_the_field(): void
    {
        $first = $this->createContent('First');
        $second = $this->createContent('Second');

        $this->addSerialField();

        $this->artisan('contents:assign-serials', [
            'space_id' => $this->space->id,
            'block' => 'item',
            'field' => 'ref',
        ])->assertSuccessful();

        // Backfilled in creation order, so the numbers match what the entries
        // would have received had the field always been there.
        $this->assertSame('1', $this->valueOf($first->fresh(), 'ref'));
        $this->assertSame('2', $this->valueOf($second->fresh(), 'ref'));
        $this->assertSame(2, ContentSerial::query()->count());
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->createContent('First');
        $this->addSerialField();

        $this->artisan('contents:assign-serials', [
            'space_id' => $this->space->id,
            'block' => 'item',
            'field' => 'ref',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, ContentSerial::query()->count());
    }

    #[Test]
    public function backfilling_twice_does_not_renumber_anything(): void
    {
        $first = $this->createContent('First');
        $this->addSerialField();

        $run = fn () => $this->artisan('contents:assign-serials', [
            'space_id' => $this->space->id,
            'block' => 'item',
            'field' => 'ref',
        ])->assertSuccessful();

        $run();
        $afterFirstRun = $this->valueOf($first->fresh(), 'ref');
        $run();

        $this->assertSame($afterFirstRun, $this->valueOf($first->fresh(), 'ref'));
        $this->assertSame(1, ContentSerial::query()->count(), 'A re-run must not add a second reservation.');
    }

    #[Test]
    public function it_adopts_values_that_already_exist_without_a_ledger_row(): void
    {
        $content = $this->createContent('Imported');
        $this->addSerialField();

        // Simulates an import that wrote the value but knew nothing of the ledger.
        $this->writeValues($content, ['ref' => '7']);

        $this->artisan('contents:assign-serials', [
            'space_id' => $this->space->id,
            'block' => 'item',
            'field' => 'ref',
        ])->assertSuccessful();

        $this->assertSame('7', $this->valueOf($content->fresh(), 'ref'), 'A live identifier must not be renumbered.');
        $this->assertDatabaseHas('content_serials', ['value' => '7', 'number' => 7]);
    }

    #[Test]
    public function reissuing_re_renders_values_but_keeps_their_numbers(): void
    {
        $first = $this->createContent('First');
        $second = $this->createContent('Second');
        $this->addSerialField();

        $this->artisan('contents:assign-serials', [
            'space_id' => $this->space->id,
            'block' => 'item',
            'field' => 'ref',
        ])->assertSuccessful();

        $this->changeSerialFormat('ITEM-{counter:4}');

        $this->artisan('contents:reissue-serials', [
            'space_id' => $this->space->id,
            'block' => 'item',
            'field' => 'ref',
        ])->assertSuccessful();

        $this->assertSame('ITEM-0001', $this->valueOf($first->fresh(), 'ref'));
        $this->assertSame('ITEM-0002', $this->valueOf($second->fresh(), 'ref'));
        $this->assertSame(
            [1, 2],
            ContentSerial::query()->orderBy('number')->pluck('number')->all(),
            'Re-issuing re-renders; it never renumbers.',
        );
    }

    #[Test]
    public function it_rejects_an_unknown_space_or_block(): void
    {
        $this->artisan('contents:assign-serials', [
            'space_id' => 'nope',
            'block' => 'item',
        ])->assertFailed();

        $this->artisan('contents:assign-serials', [
            'space_id' => $this->space->id,
            'block' => 'does-not-exist',
        ])->assertFailed();
    }

    #[Test]
    public function it_rejects_a_block_without_serial_fields(): void
    {
        $this->artisan('contents:assign-serials', [
            'space_id' => $this->space->id,
            'block' => 'item',
        ])->assertFailed();
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function createBlock(array $schema): Block
    {
        $block = new Block([
            'name' => 'Item',
            'slug' => 'item',
            'type' => 'root',
            'schema' => $schema,
            'editor' => [['header' => 'General', 'items' => array_keys($schema)]],
        ]);
        $block->save();

        return $block->fresh();
    }

    protected function addSerialField(string $format = '{counter}'): void
    {
        $block = Block::query()->where('slug', 'item')->firstOrFail();
        $block->schema = [
            'title' => ['type' => 'text'],
            'ref' => ['type' => 'serial', 'format' => $format, 'scope' => ['block']],
        ];
        $block->editor = [['header' => 'General', 'items' => ['title', 'ref']]];
        $block->save();

        $this->block = $block->fresh();
    }

    protected function changeSerialFormat(string $format): void
    {
        $this->addSerialField($format);
    }

    protected function createContent(string $name): Content
    {
        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/contents", [
            'name' => $name,
            'slug' => \Str::slug($name),
            'block_id' => $this->block->id,
        ]);

        $response->assertCreated();

        return Content::query()->findOrFail($response->json('data.id'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function writeValues(Content $content, array $values): void
    {
        $version = $content->current_version;
        $version->content = array_replace($version->content ?? [], $values);
        $version->save();
    }

    protected function valueOf(Content $content, string $key): mixed
    {
        return $content->getCurrentContent()[$key] ?? null;
    }
}
