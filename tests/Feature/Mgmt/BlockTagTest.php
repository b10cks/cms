<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\BlockTagController;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(BlockTagController::class)]
class BlockTagTest extends TestCase
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
    public function deleting_a_tag_removes_it_from_all_blocks()
    {
        BlockTag::forceCreate(['name' => 'hero']);
        BlockTag::forceCreate(['name' => 'other']);

        $tagged = Block::factory()->create(['tags' => ['hero', 'other']]);
        $untagged = Block::factory()->create(['tags' => ['other']]);

        $response = $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/block-tags/hero");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('block_tags', ['name' => 'hero']);
        $this->assertSame(['other'], $tagged->fresh()->tags);
        $this->assertSame(['other'], $untagged->fresh()->tags);
    }

    #[Test]
    public function deleting_an_unused_tag_leaves_blocks_untouched()
    {
        BlockTag::forceCreate(['name' => 'unused']);

        $block = Block::factory()->create(['tags' => ['other']]);

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/block-tags/unused")
            ->assertStatus(204);

        $this->assertSame(['other'], $block->fresh()->tags);
    }
}
