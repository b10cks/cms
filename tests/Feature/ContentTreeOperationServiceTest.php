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
    public function it_creates_items_with_the_provided_initial_content(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);

        $block = Block::factory()->create([
            'type' => 'root',
            'schema' => [
                'headline' => ['type' => 'text'],
                'meta' => [
                    'type' => 'object',
                    'fields' => [
                        'featured' => ['type' => 'checkbox'],
                    ],
                ],
            ],
        ]);

        $result = app(ContentTreeOperationService::class)->createItem(
            null,
            [
                'block_id' => $block->id,
                'name' => 'Template content',
                'slug' => 'template-content',
                'content' => [
                    'headline' => 'Hello template',
                    'meta' => ['featured' => true],
                ],
            ],
            $space,
            $this->user,
        );

        /** @var Content $created */
        $created = $result['created'][0]->fresh(['current_version']);

        $this->assertSame([
            'headline' => 'Hello template',
            'meta' => ['featured' => true],
        ], $created->current_version->content);
    }

    #[Test]
    public function it_moves_items_under_a_nested_parent_without_lazy_loading_the_parent_relation(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);

        $block = Block::factory()->create([
            'type' => 'root',
        ]);

        $root = $this->createContentItem($space, $block, 'Root', 'root');
        $nestedParent = $this->createContentItem($space, $block, 'Nested Parent', 'nested-parent', $root);
        $movable = $this->createContentItem($space, $block, 'Movable', 'movable');

        app(ContentTreeOperationService::class)->moveItems([$movable->id], $nestedParent->id, null, $space);

        $this->assertSame($nestedParent->id, $movable->fresh()->parent_id);
    }

    #[Test]
    public function it_reorders_siblings_when_moving_after_an_anchor(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);
        $this->enableContentSorting($space);

        $block = Block::factory()->create([
            'type' => 'root',
        ]);

        $first = $this->createContentItem($space, $block, 'First', 'first');
        $second = $this->createContentItem($space, $block, 'Second', 'second');
        $third = $this->createContentItem($space, $block, 'Third', 'third');

        app(ContentTreeOperationService::class)->moveItems([$third->id], null, $first->id, $space);

        $orderedIds = Content::query()
            ->whereNull('parent_id')
            ->orderBy('position')
            ->pluck('id')
            ->all();

        $this->assertSame([$first->id, $third->id, $second->id], $orderedIds);
        $this->assertSame(0, $first->fresh()->position);
        $this->assertSame(1, $third->fresh()->position);
        $this->assertSame(2, $second->fresh()->position);
    }

    #[Test]
    public function it_appends_sequential_positions_on_create_when_sorting_is_enabled(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);
        $this->enableContentSorting($space);

        $block = Block::factory()->create(['type' => 'root']);

        $first = $this->createContentItem($space, $block, 'First', 'first');
        $second = $this->createContentItem($space, $block, 'Second', 'second');
        $third = $this->createContentItem($space, $block, 'Third', 'third');

        $this->assertSame(0, $first->fresh()->position);
        $this->assertSame(1, $second->fresh()->position);
        $this->assertSame(2, $third->fresh()->position);
    }

    #[Test]
    public function it_does_not_assign_positions_on_create_when_sorting_is_disabled(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);

        $block = Block::factory()->create(['type' => 'root']);

        $first = $this->createContentItem($space, $block, 'First', 'first');
        $second = $this->createContentItem($space, $block, 'Second', 'second');

        // Sorting is opt-in: positions stay at the default 0 so ordering falls back to name.
        $this->assertSame(0, $first->fresh()->position);
        $this->assertSame(0, $second->fresh()->position);
    }

    #[Test]
    public function it_reparents_without_reordering_when_sorting_is_disabled(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);

        $block = Block::factory()->create(['type' => 'root']);

        $first = $this->createContentItem($space, $block, 'First', 'first');
        $second = $this->createContentItem($space, $block, 'Second', 'second');
        $third = $this->createContentItem($space, $block, 'Third', 'third');

        // An explicit reorder request is ignored for ordering while sorting is disabled,
        // but the move itself (here a no-op reparent to root) must still succeed.
        app(ContentTreeOperationService::class)->moveItems([$third->id], null, $first->id, $space);

        $this->assertSame(0, $first->fresh()->position);
        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(0, $third->fresh()->position);
        $this->assertNull($third->fresh()->parent_id);
    }

    #[Test]
    public function moving_content_does_not_unpublish_it(): void
    {
        $this->createAndActAs();

        $space = Space::factory()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);

        $block = Block::factory()->create([
            'type' => 'root',
        ]);

        $parent = $this->createContentItem($space, $block, 'Parent', 'parent');
        $content = $this->createContentItem($space, $block, 'Published', 'published');
        $content->forceFill([
            'published_version_id' => $content->current_version_id,
            'published_at' => now(),
            'first_published_at' => now(),
        ])->save();

        app(ContentTreeOperationService::class)->moveItems([$content->id], $parent->id, null, $space);

        $content->refresh();
        $this->assertSame($parent->id, $content->parent_id);
        $this->assertNotNull($content->published_version_id);
        $this->assertNotNull($content->published_at);
    }

    protected function enableContentSorting(Space $space): void
    {
        $space->settings = array_merge($space->settings->toArray(), ['content_sorting' => true]);
        $space->save();
    }

    protected function createContentItem(
        Space $space,
        Block $block,
        string $name,
        string $slug,
        ?Content $parent = null,
    ): Content {
        $content = new Content;

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
