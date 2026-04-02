<?php

namespace Tests\Feature\Mgmt\Content;

use App\Actions\Content\CreateContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentChildPlacementTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected Block $articleBlock;

    protected Block $landingBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = $this->createBlock('page', 'Page', 'root', ['pages']);
        $this->articleBlock = $this->createBlock('article', 'Article', 'root', ['articles']);
        $this->landingBlock = $this->createBlock('landing', 'Landing', 'universal', ['marketing']);
    }

    #[Test]
    public function create_endpoint_rejects_disallowed_child_content_type(): void
    {
        $this->actingAs($this->owner);
        $parent = $this->createRestrictedParent();

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Child',
            'slug' => 'child',
            'block_id' => $this->landingBlock->id,
            'parent_id' => $parent->id,
            'content' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['block_id']);
    }

    #[Test]
    public function tree_create_rejects_disallowed_child_content_type(): void
    {
        $this->actingAs($this->owner);
        $parent = $this->createRestrictedParent();

        $response = $this->postJson(route('mgmt.contents.tree-operations', [
            'space' => $this->space->id,
        ]), [
            'operations' => [[
                'type' => 'create',
                'temp_id' => 'temp-child',
                'parent_id' => $parent->id,
                'block_id' => $this->landingBlock->id,
                'name' => 'Child',
                'slug' => 'child',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['block_id']);
    }

    #[Test]
    public function tree_move_rejects_disallowed_child_content_type(): void
    {
        $this->actingAs($this->owner);
        $parent = $this->createRestrictedParent();
        $movable = $this->createContent('movable', $this->landingBlock);

        $response = $this->postJson(route('mgmt.contents.tree-operations', [
            'space' => $this->space->id,
        ]), [
            'operations' => [[
                'type' => 'move',
                'ids' => [$movable->id],
                'parent_id' => $parent->id,
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['block_id']);
    }

    #[Test]
    public function tree_duplicate_rejects_disallowed_child_content_type(): void
    {
        $this->actingAs($this->owner);
        $parent = $this->createRestrictedParent();
        $source = $this->createContent('source', $this->landingBlock);

        $response = $this->postJson(route('mgmt.contents.tree-operations', [
            'space' => $this->space->id,
        ]), [
            'operations' => [[
                'type' => 'duplicate',
                'ids' => [$source->id],
                'parent_id' => $parent->id,
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['block_id']);
    }

    #[Test]
    public function tree_update_block_rejects_disallowed_child_content_type(): void
    {
        $this->actingAs($this->owner);
        $parent = $this->createRestrictedParent();
        $child = $this->createContent('child', $this->articleBlock, null, 'en', [], $parent);

        $response = $this->postJson(route('mgmt.contents.tree-operations', [
            'space' => $this->space->id,
        ]), [
            'operations' => [[
                'type' => 'update_block',
                'id' => $child->id,
                'block_id' => $this->landingBlock->id,
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['block_id']);
    }

    private function createRestrictedParent(): Content
    {
        return $this->createContent('parent', $this->pageBlock, null, 'en', [
            'restrict_child_blocks' => true,
            'child_block_whitelist' => [$this->articleBlock->slug],
            'child_tag_whitelist' => [],
            'default_child_block' => $this->articleBlock->id,
        ]);
    }

    private function createBlock(string $slug, string $name, string $type, array $tags = []): Block
    {
        return Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'tags' => $tags,
        ]);
    }

    private function createContent(
        string $slug,
        Block $block,
        ?Content $i18nParent = null,
        string $languageIso = 'en',
        array $settings = [],
        ?Content $parent = null,
    ): Content {
        $content = new Content();

        app(CreateContent::class)->execute([
            'block_id' => $block->id,
            'parent_id' => $parent?->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
            'content' => ['title' => ucfirst($slug)],
            'settings' => $settings,
        ], $content, $this->space, $this->owner);

        return $content->fresh();
    }
}
