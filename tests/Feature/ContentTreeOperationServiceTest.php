<?php

namespace Tests\Feature;

use App\Actions\Content\CreateContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Content\ContentTreeOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentTreeOperationServiceTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function it_moves_items_under_a_nested_parent_without_lazy_loading_the_parent_relation(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);

        $block = Block::factory()->create([
            'type' => 'nestable',
        ]);

        $root = $this->createContentItem($space, $block, 'Root', 'root');
        $nestedParent = $this->createContentItem($space, $block, 'Nested Parent', 'nested-parent', $root);
        $movable = $this->createContentItem($space, $block, 'Movable', 'movable');

        app(ContentTreeOperationService::class)->moveItems([$movable->id], $nestedParent->id, null, $space);

        $this->assertSame($nestedParent->id, $movable->fresh()->parent_id);
    }

    protected function createContentItem(
        Space $space,
        Block $block,
        string $name,
        string $slug,
        ?Content $parent = null,
    ): Content {
        $content = new Content();

        app(CreateContent::class)->execute([
            'block_id' => $block->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'slug' => $slug,
            'language_iso' => 'en',
            'content' => [],
            'settings' => [],
        ], $content, $space, $this->user);

        return $content->fresh();
    }
}
