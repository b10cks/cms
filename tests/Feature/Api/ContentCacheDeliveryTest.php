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

class ContentCacheDeliveryTest extends TestCase
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
            'token' => 'content-cache-token',
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
    public function delivery_exposes_cache_settings_and_headers(): void
    {
        $this->createPublishedContent('news-item', ['title' => 'News'], [
            'cache_ttl' => 120,
            'cache_tags' => ['news', 'home'],
        ]);

        $response = $this->getJson($this->showUrl('news-item'));

        $response->assertOk();
        $response->assertJsonPath('data.cache.ttl', 120);
        $response->assertJsonPath('data.cache.tags', ['news', 'home']);
        $response->assertHeader('cache-control', 'max-age=120, public, s-maxage=120');
        $response->assertHeader('cache-tag', 'news,home');
    }

    #[Test]
    public function delivery_uses_default_cache_behaviour_without_settings(): void
    {
        $this->createPublishedContent('home', ['title' => 'Home']);

        $response = $this->getJson($this->showUrl('home'));

        $response->assertOk();
        $response->assertJsonPath('data.cache.ttl', null);
        $response->assertJsonPath('data.cache.tags', []);
        $response->assertHeader('cache-control', 'max-age=3600, public, s-maxage=86400');
        $response->assertHeaderMissing('cache-tag');
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

    private function createPublishedContent(string $slug, array $content, array $settings = []): Content
    {
        $model = new Content;
        $model->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => 'en',
            'settings' => $settings,
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
