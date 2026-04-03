<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentRelationsTest extends TestCase
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
                'languages' => [
                    ['code' => 'en', 'name' => 'English', 'fallback_language' => null],
                ],
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'content-relations-token',
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
                'title' => [
                    'type' => 'text',
                ],
                'cta' => [
                    'type' => 'link',
                ],
                'related' => [
                    'type' => 'references',
                ],
            ],
        ]);
    }

    #[Test]
    public function data_api_show_does_not_include_relations_without_the_dedicated_query_param(): void
    {
        $related = $this->createPublishedContent('related-page', [
            'title' => 'Related page',
        ]);
        $home = $this->createPublishedContent('home', [
            'title' => 'Home',
            'related' => [$related->id],
        ]);

        $response = $this->getJson($this->showUrl($home->slug));

        $response->assertOk();
        $response->assertJsonMissingPath('data.relations');
    }

    #[Test]
    public function data_api_show_returns_first_level_resolved_relations_when_requested(): void
    {
        $linkTarget = $this->createPublishedContent('linked-page', [
            'title' => 'Linked page',
        ]);
        $nestedRelation = $this->createPublishedContent('nested-relation', [
            'title' => 'Nested relation',
        ]);
        $relationA = $this->createPublishedContent('relation-a', [
            'title' => 'Relation A',
            'cta' => [
                'type' => 'internal',
                'content' => $linkTarget->id,
            ],
            'related' => [$nestedRelation->id],
        ]);
        $relationB = $this->createPublishedContent('relation-b', [
            'title' => 'Relation B',
        ]);
        $home = $this->createPublishedContent('home', [
            'title' => 'Home',
            'related' => [$relationA->id, $relationB->id],
        ]);

        $response = $this->getJson($this->showUrl($home->slug, [
            'resolve_relations' => 1,
        ]));

        $response->assertOk();
        $response->assertJsonCount(2, 'data.relations');

        $relations = collect($response->json('data.relations'));
        $resolvedRelationA = $relations->firstWhere('id', $relationA->id);
        $resolvedRelationB = $relations->firstWhere('id', $relationB->id);

        $this->assertNotNull($resolvedRelationA);
        $this->assertNotNull($resolvedRelationB);
        $this->assertSame('Relation A', data_get($resolvedRelationA, 'content.title'));
        $this->assertSame('/linked-page', data_get($resolvedRelationA, 'content.cta.url'));
        $this->assertSame('Linked Page', data_get($resolvedRelationA, 'content.cta.title'));
        $this->assertArrayNotHasKey('relations', $resolvedRelationA);
    }

    private function showUrl(string $slug, array $query = []): string
    {
        return route('api.contents.show', [
            'slug' => $slug,
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function createPublishedContent(string $slug, array $content): Content
    {
        $model = new Content;
        $model->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => 'en',
            'settings' => [],
        ]);
        $model->id = strtolower((string) Str::ulid());

        $version = ContentVersion::createWithContentContext([
            'content_id' => $model->id,
            'content' => $content,
            'published_at' => now(),
        ], $model->setRelation('block', $this->pageBlock));

        $model->current_version_id = $version->id;
        $model->published_version_id = $version->id;
        $model->published_at = $version->published_at;
        $model->save();

        return $model->fresh();
    }
}
