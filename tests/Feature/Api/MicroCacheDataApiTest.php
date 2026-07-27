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

class MicroCacheDataApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Token $token;

    private Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.micro_cache_ttl', 60);

        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'micro-cache-token',
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
    public function second_request_for_the_same_url_is_served_from_the_micro_cache(): void
    {
        $this->createPublishedContent('home', ['title' => 'Home'], [
            'cache_ttl' => 120,
            'cache_tags' => ['home'],
        ]);

        $miss = $this->getJson($this->showUrl('home'));
        $miss->assertOk();
        $miss->assertHeader('x-b10cks-cache', 'miss');

        $hit = $this->getJson($this->showUrl('home'));
        $hit->assertOk();
        $hit->assertHeader('x-b10cks-cache', 'hit');
        // Cached responses re-emit the controller-provided cache settings.
        $hit->assertHeader('cache-control', 'max-age=120, public, s-maxage=120, stale-if-error=86400, stale-while-revalidate=60');
        $hit->assertHeader('cache-tag', 'home');
        $this->assertSame($miss->getContent(), $hit->getContent());
    }

    #[Test]
    public function different_revisions_use_different_cache_entries(): void
    {
        $this->createPublishedContent('home', ['title' => 'Home']);

        $this->getJson($this->showUrl('home'))->assertHeader('x-b10cks-cache', 'miss');
        $this->getJson($this->showUrl('home', ['rv' => 12345]))->assertHeader('x-b10cks-cache', 'miss');
    }

    #[Test]
    public function error_responses_are_not_cached(): void
    {
        $this->getJson($this->showUrl('missing'))->assertNotFound();
        $this->getJson($this->showUrl('missing'))->assertNotFound()->assertHeader('x-b10cks-cache', 'miss');
    }

    #[Test]
    public function micro_cache_is_disabled_when_ttl_is_zero(): void
    {
        config()->set('app.micro_cache_ttl', 0);

        $this->createPublishedContent('home', ['title' => 'Home']);

        $this->getJson($this->showUrl('home'))->assertOk()->assertHeaderMissing('x-b10cks-cache');
        $this->getJson($this->showUrl('home'))->assertOk()->assertHeaderMissing('x-b10cks-cache');
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
