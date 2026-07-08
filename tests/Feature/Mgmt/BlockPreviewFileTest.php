<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\BlockController;
use App\Http\Controllers\Mgmt\BlockTemplateController;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(BlockController::class)]
#[CoversClass(BlockTemplateController::class)]
class BlockPreviewFileTest extends TestCase
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
    public function a_block_can_be_created_with_a_preview_file()
    {
        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/blocks", [
            'name' => 'Hero',
            'slug' => 'hero',
            'type' => 'nestable',
            'preview_file' => 'storage-id/space-id/asset-id/hero.png',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.preview_file', 'storage-id/space-id/asset-id/hero.png');
    }

    #[Test]
    public function a_block_preview_file_can_be_updated_and_cleared()
    {
        $block = Block::factory()->create();

        $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/blocks/{$block->id}", [
            'preview_file' => 'storage-id/space-id/asset-id/preview.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.preview_file', 'storage-id/space-id/asset-id/preview.png');

        $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/blocks/{$block->id}", [
            'preview_file' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.preview_file', null);

        $this->assertNull($block->fresh()->preview_file);
    }

    #[Test]
    public function a_block_preview_file_must_not_exceed_255_characters()
    {
        $block = Block::factory()->create();

        $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/blocks/{$block->id}", [
            'preview_file' => str_repeat('a', 256),
        ])->assertUnprocessable()->assertJsonValidationErrors('preview_file');
    }

    #[Test]
    public function a_block_template_can_be_created_with_a_preview_file()
    {
        $block = Block::factory()->create();

        $response = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/blocks/{$block->id}/templates", [
            'name' => 'Default',
            'content' => ['foo' => 'bar'],
            'preview_file' => 'storage-id/space-id/asset-id/template.png',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.preview_file', 'storage-id/space-id/asset-id/template.png');
    }

    #[Test]
    public function a_block_template_preview_file_can_be_updated_and_cleared()
    {
        $block = Block::factory()->create();
        $template = BlockTemplate::forceCreate([
            'block_id' => $block->id,
            'name' => 'Default',
            'content' => ['foo' => 'bar'],
            'preview_file' => 'storage-id/space-id/asset-id/old.png',
        ]);

        $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/blocks/{$block->id}/templates/{$template->id}", [
            'preview_file' => 'storage-id/space-id/asset-id/new.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.preview_file', 'storage-id/space-id/asset-id/new.png');

        $this->patchJson("/mgmt/v1/spaces/{$this->space->id}/blocks/{$block->id}/templates/{$template->id}", [
            'preview_file' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.preview_file', null);

        $this->assertNull($template->fresh()->preview_file);
    }
}
