<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentChildOrderingTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Token $token;

    private Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'child-ordering-token',
            'expires_at' => null,
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text'],
            ],
        ]);
    }

    #[Test]
    public function children_are_ordered_by_the_sorting_configured_on_their_parent(): void
    {
        $folder = $this->createPublishedContent('news', settings: [
            'child_sort_by' => 'published_at',
            'child_sort_direction' => 'desc',
        ]);

        $oldest = $this->createPublishedContent('oldest', parent: $folder, position: 0, publishedAt: now()->subDays(3));
        $newest = $this->createPublishedContent('newest', parent: $folder, position: 1, publishedAt: now()->subDay());
        $middle = $this->createPublishedContent('middle', parent: $folder, position: 2, publishedAt: now()->subDays(2));

        $response = $this->getJson($this->indexUrl(['parent_id' => $folder->id]));

        $response->assertOk();
        $this->assertSame(
            [$newest->id, $middle->id, $oldest->id],
            array_column($response->json('data'), 'id'),
        );
    }

    #[Test]
    public function an_explicit_sort_parameter_overrides_the_configured_child_sorting(): void
    {
        $folder = $this->createPublishedContent('news', settings: [
            'child_sort_by' => 'published_at',
            'child_sort_direction' => 'desc',
        ]);

        $first = $this->createPublishedContent('first', parent: $folder, position: 0, publishedAt: now()->subDays(3));
        $second = $this->createPublishedContent('second', parent: $folder, position: 1, publishedAt: now()->subDay());
        $third = $this->createPublishedContent('third', parent: $folder, position: 2, publishedAt: now()->subDays(2));

        $response = $this->getJson($this->indexUrl([
            'parent_id' => $folder->id,
            'sort' => 'position',
        ]));

        $response->assertOk();
        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($response->json('data'), 'id'),
        );
    }

    #[Test]
    public function manual_child_sorting_orders_children_by_position(): void
    {
        $folder = $this->createPublishedContent('news', settings: [
            'child_sort_by' => 'manual',
        ]);

        $second = $this->createPublishedContent('second', parent: $folder, position: 1, publishedAt: now()->subDays(3));
        $first = $this->createPublishedContent('first', parent: $folder, position: 0, publishedAt: now()->subDay());

        $response = $this->getJson($this->indexUrl(['parent_id' => $folder->id]));

        $response->assertOk();
        $this->assertSame(
            [$first->id, $second->id],
            array_column($response->json('data'), 'id'),
        );
    }

    #[Test]
    public function children_are_ordered_by_a_first_level_content_field(): void
    {
        $folder = $this->createPublishedContent('news', settings: [
            'child_sort_by' => 'content.publishDate',
            'child_sort_direction' => 'desc',
        ]);

        $oldest = $this->createPublishedContent('oldest', parent: $folder, position: 0, content: [
            'publishDate' => '2026-01-05',
        ]);
        $newest = $this->createPublishedContent('newest', parent: $folder, position: 1, content: [
            'publishDate' => '2026-03-20',
        ]);
        $middle = $this->createPublishedContent('middle', parent: $folder, position: 2, content: [
            'publishDate' => '2026-02-11',
        ]);

        $response = $this->getJson($this->indexUrl(['parent_id' => $folder->id]));

        $response->assertOk();
        $this->assertSame(
            [$newest->id, $middle->id, $oldest->id],
            array_column($response->json('data'), 'id'),
        );
    }

    #[Test]
    public function folders_without_child_sorting_return_all_children(): void
    {
        $folder = $this->createPublishedContent('news');

        $childA = $this->createPublishedContent('child-a', parent: $folder, position: 0);
        $childB = $this->createPublishedContent('child-b', parent: $folder, position: 1);

        $response = $this->getJson($this->indexUrl(['parent_id' => $folder->id]));

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        $expected = [$childA->id, $childB->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    private function indexUrl(array $query = []): string
    {
        return route('api.contents.index', [
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function createPublishedContent(
        string $slug,
        array $settings = [],
        ?Content $parent = null,
        int $position = 0,
        ?Carbon $publishedAt = null,
        array $content = [],
    ): Content {
        $model = new Content;
        $model->forceFill([
            'block_id' => $this->pageBlock->id,
            'parent_id' => $parent?->id,
            'position' => $position,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => $parent ? "{$parent->full_slug}/{$slug}" : "/{$slug}",
            'language_iso' => 'en',
            'settings' => $settings,
        ]);
        $model->id = strtolower((string) Str::ulid());

        $version = ContentVersion::createWithContentContext([
            'content_id' => $model->id,
            'content' => ['title' => Str::headline($slug), ...$content],
            'published_at' => $publishedAt ?? now(),
        ], $model->setRelation('block', $this->pageBlock));

        $model->current_version_id = $version->id;
        $model->published_version_id = $version->id;
        $model->published_at = $publishedAt ?? now();
        $model->save();

        return $model->fresh();
    }
}
