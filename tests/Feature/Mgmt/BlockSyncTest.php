<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\BlockController;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(BlockController::class)]
class BlockSyncTest extends TestCase
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

    protected function heroPayload(array $overrides = []): array
    {
        return array_merge([
            'external_id' => '01J0000000000000000000HERO',
            'name' => 'Hero',
            'slug' => 'hero',
            'type' => 'nestable',
            'schema' => [
                'title' => ['type' => 'text', 'required' => true],
                'image' => ['type' => 'asset'],
            ],
        ], $overrides);
    }

    protected function sync(array $payload)
    {
        return $this->putJson("/mgmt/v1/spaces/{$this->space->id}/blocks/sync", $payload);
    }

    #[Test]
    public function it_creates_blocks_from_an_empty_space()
    {
        $response = $this->sync(['blocks' => [$this->heroPayload()]]);

        $response->assertOk()
            ->assertJsonPath('data.summary.created', 1)
            ->assertJsonPath('data.results.0.action', 'created');

        $block = Block::where('slug', 'hero')->firstOrFail();
        $this->assertSame('01J0000000000000000000HERO', $block->external_id);
        $this->assertTrue($block->schema->getField('title')->isRequired());
    }

    #[Test]
    public function a_second_sync_with_the_same_payload_is_a_no_op()
    {
        $this->sync(['blocks' => [$this->heroPayload()]])->assertOk();

        $this->sync(['blocks' => [$this->heroPayload()]])
            ->assertOk()
            ->assertJsonPath('data.summary.unchanged', 1)
            ->assertJsonPath('data.summary.updated', 0)
            ->assertJsonPath('data.summary.created', 0);

        $this->assertSame(0, BlockVersion::count());
    }

    #[Test]
    public function it_updates_a_changed_block_and_creates_a_version()
    {
        $this->sync(['blocks' => [$this->heroPayload()]])->assertOk();

        $response = $this->sync([
            'blocks' => [$this->heroPayload(['name' => 'Hero Section'])],
            'commit_message' => 'rename hero',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.updated', 1)
            ->assertJsonPath('data.results.0.action', 'updated');

        $this->assertContains('name', $response->json('data.results.0.changed'));

        $block = Block::where('slug', 'hero')->firstOrFail();
        $this->assertSame('Hero Section', $block->name);

        $version = BlockVersion::where('block_id', $block->id)->firstOrFail();
        $this->assertSame('rename hero', $version->commit_message);
        $this->assertSame('Hero', $version->data['name']);
    }

    #[Test]
    public function it_adopts_an_existing_block_by_slug_and_assigns_the_external_id()
    {
        $block = Block::factory()->create(['slug' => 'hero', 'external_id' => null]);

        $this->sync(['blocks' => [$this->heroPayload()]])
            ->assertOk()
            ->assertJsonPath('data.results.0.action', 'updated')
            ->assertJsonPath('data.results.0.id', $block->id);

        $this->assertSame('01J0000000000000000000HERO', $block->fresh()->external_id);
    }

    #[Test]
    public function dry_run_reports_the_plan_without_applying_changes()
    {
        $this->sync(['blocks' => [$this->heroPayload()]])->assertOk();
        $orphan = Block::factory()->create(['slug' => 'orphan']);

        $response = $this->sync([
            'blocks' => [$this->heroPayload(['name' => 'Hero Section'])],
            'prune' => true,
            'dry_run' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.summary.updated', 1)
            ->assertJsonPath('data.summary.deleted', 1);

        $this->assertSame('Hero', Block::where('slug', 'hero')->firstOrFail()->name);
        $this->assertNotNull($orphan->fresh());
        $this->assertSame(0, BlockVersion::count());
    }

    #[Test]
    public function prune_soft_deletes_blocks_missing_from_the_payload()
    {
        $orphan = Block::factory()->create(['slug' => 'orphan']);

        $this->sync(['blocks' => [$this->heroPayload()], 'prune' => true])
            ->assertOk()
            ->assertJsonPath('data.summary.deleted', 1);

        $this->assertSoftDeleted($orphan);
        $this->assertNotNull(Block::where('slug', 'hero')->first());
    }

    #[Test]
    public function it_rejects_a_slug_owned_by_a_different_synced_block()
    {
        Block::factory()->create(['slug' => 'hero', 'external_id' => 'other-external-id']);

        $this->sync(['blocks' => [$this->heroPayload()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['blocks.0.slug']);
    }

    #[Test]
    public function it_rejects_duplicate_external_ids_in_the_payload()
    {
        $this->sync([
            'blocks' => [
                $this->heroPayload(),
                $this->heroPayload(['slug' => 'teaser', 'name' => 'Teaser']),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['blocks.1.external_id']);
    }

    #[Test]
    public function it_requires_the_blocks_manage_ability()
    {
        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        Sanctum::actingAs($viewer);

        $this->sync(['blocks' => [$this->heroPayload()]])->assertForbidden();
    }
}
