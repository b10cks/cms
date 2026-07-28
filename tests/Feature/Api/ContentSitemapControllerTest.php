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

class ContentSitemapControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Token $token;

    private Block $pageBlock;

    private Block $articleBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'en', 'name' => 'English', 'fallback_language' => null],
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                ],
                'sitemap' => [
                    'types' => [
                        ['block' => 'page', 'path' => 'meta'],
                    ],
                ],
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'content-sitemap-token',
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
                'meta' => [
                    'type' => 'meta',
                ],
            ],
        ]);

        $this->articleBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Article',
            'slug' => 'article',
            'type' => 'root',
            'schema' => [
                'meta' => [
                    'type' => 'meta',
                ],
            ],
        ]);
    }

    #[Test]
    public function contents_sitemap_route_wins_over_the_slug_route(): void
    {
        $this->createPublishedContent(
            slug: 'sitemap',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'index,follow']],
        );

        $response = $this->getJson($this->sitemapUrl());

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'full_slug', 'meta', 'published_at'],
            ],
        ]);
        $response->assertJsonMissingPath('data.slug');
    }

    #[Test]
    public function sitemap_returns_only_published_configured_default_language_rows_by_default(): void
    {
        $canonical = $this->createPublishedContent(
            slug: 'home',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'follow,index']],
        );
        $this->createPublishedContent(
            slug: 'startseite',
            block: $this->pageBlock,
            languageIso: 'de',
            i18nParent: $canonical,
            content: ['meta' => ['title' => 'Startseite']],
        );
        $this->createPublishedContent(
            slug: 'article',
            block: $this->articleBlock,
            content: ['meta' => ['robots' => 'index,follow']],
        );
        $this->createDraftContent(
            slug: 'draft-page',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'index,follow']],
        );

        $response = $this->getJson($this->sitemapUrl());

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $canonical->id);
        $response->assertJsonPath('data.0.meta.robots', 'index,follow');
    }

    #[Test]
    public function sitemap_uses_explicit_language_rows_and_effective_overlay_meta(): void
    {
        $target = $this->createPublishedContent(
            slug: 'canonical-target',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'index,follow']],
        );
        $canonical = $this->createPublishedContent(
            slug: 'home',
            block: $this->pageBlock,
            content: [
                'meta' => [
                    'canonical' => [
                        'type' => 'internal',
                        'content' => $target->id,
                    ],
                    'robots' => 'index,follow',
                ],
            ],
        );
        $translation = $this->createPublishedContent(
            slug: 'startseite',
            block: $this->pageBlock,
            languageIso: 'de',
            i18nParent: $canonical,
            content: ['meta' => ['title' => 'Startseite']],
        );

        $response = $this->getJson($this->sitemapUrl([
            'language_iso' => 'de',
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $translation->id);
        $response->assertJsonPath('data.0.full_slug', '/startseite');
        $response->assertJsonPath('data.0.meta.canonical', '/canonical-target');
        $response->assertJsonPath('data.0.meta.robots', 'index,follow');
    }

    #[Test]
    public function sitemap_excludes_rows_with_noindex_or_none_but_keeps_missing_meta_and_legacy_canonical_strings(): void
    {
        $includedWithoutMeta = $this->createPublishedContent(
            slug: 'without-meta',
            block: $this->pageBlock,
            content: [],
        );
        $includedWithLegacyCanonical = $this->createPublishedContent(
            slug: 'legacy-canonical',
            block: $this->pageBlock,
            content: [
                'meta' => [
                    'canonical' => 'https://example.com/custom-canonical',
                    'robots' => 'follow,index',
                ],
            ],
        );
        $this->createPublishedContent(
            slug: 'excluded-noindex',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'noindex,follow']],
        );
        $this->createPublishedContent(
            slug: 'excluded-none',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'none']],
        );

        $response = $this->getJson($this->sitemapUrl());

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.meta.canonical', 'https://example.com/custom-canonical');
        $response->assertJsonPath('data.1.id', $includedWithoutMeta->id);
        $response->assertJsonPath('data.1.meta.robots', null);
        $response->assertJsonPath('data.1.meta.canonical', null);

        $items = collect($response->json('data'));
        $this->assertTrue($items->pluck('id')->contains($includedWithLegacyCanonical->id));
        $this->assertTrue($items->pluck('id')->contains($includedWithoutMeta->id));
    }

    #[Test]
    public function sitemap_paginates_in_the_database_and_excludes_noindex_from_totals(): void
    {
        foreach (['alpha', 'bravo', 'charlie'] as $slug) {
            $this->createPublishedContent(
                slug: $slug,
                block: $this->pageBlock,
                content: ['meta' => ['robots' => 'index,follow']],
            );
        }
        $this->createPublishedContent(
            slug: 'blocked',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'noindex']],
        );

        $firstPage = $this->getJson($this->sitemapUrl(['per_page' => 2]));

        $firstPage->assertOk();
        $firstPage->assertJsonCount(2, 'data');
        $firstPage->assertJsonPath('meta.total', 3);
        $firstPage->assertJsonPath('meta.last_page', 2);
        $firstPage->assertJsonPath('data.0.full_slug', '/alpha');
        $firstPage->assertJsonPath('data.1.full_slug', '/bravo');

        $secondPage = $this->getJson($this->sitemapUrl(['per_page' => 2, 'page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertJsonCount(1, 'data');
        $secondPage->assertJsonPath('data.0.full_slug', '/charlie');
    }

    #[Test]
    public function named_sitemaps_serve_only_their_configured_blocks_with_their_own_meta_paths(): void
    {
        $this->configureNamedSitemaps();

        $page = $this->createPublishedContent(
            slug: 'home',
            block: $this->pageBlock,
            content: ['meta' => ['robots' => 'follow,index']],
        );
        $article = $this->createPublishedContent(
            slug: 'launch-post',
            block: $this->articleBlock,
            content: ['seo' => ['robots' => 'index,follow', 'canonical' => 'https://example.com/launch']],
        );

        $pages = $this->getJson($this->namedSitemapUrl('pages'));

        $pages->assertOk();
        $pages->assertJsonCount(1, 'data');
        $pages->assertJsonPath('data.0.id', $page->id);
        $pages->assertJsonPath('data.0.meta.robots', 'index,follow');

        $news = $this->getJson($this->namedSitemapUrl('news'));

        $news->assertOk();
        $news->assertJsonCount(1, 'data');
        $news->assertJsonPath('data.0.id', $article->id);
        $news->assertJsonPath('data.0.meta.robots', 'index,follow');
        $news->assertJsonPath('data.0.meta.canonical', 'https://example.com/launch');
    }

    #[Test]
    public function named_sitemap_honors_noindex_within_its_own_meta_path(): void
    {
        $this->configureNamedSitemaps();

        $this->createPublishedContent(
            slug: 'hidden-article',
            block: $this->articleBlock,
            content: ['seo' => ['robots' => 'noindex,follow']],
        );

        $news = $this->getJson($this->namedSitemapUrl('news'));

        $news->assertOk();
        $news->assertJsonCount(0, 'data');
        $news->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function unknown_named_sitemap_returns_404(): void
    {
        $this->configureNamedSitemaps();

        $this->getJson($this->namedSitemapUrl('unknown'))->assertNotFound();
    }

    private function configureNamedSitemaps(): void
    {
        $this->space->update([
            'settings' => [
                ...$this->space->settings->toArray(),
                'sitemaps' => [
                    ['slug' => 'pages', 'types' => [['block' => 'page', 'path' => 'meta']]],
                    ['slug' => 'news', 'types' => [['block' => 'article', 'path' => 'seo']]],
                ],
            ],
        ]);
    }

    private function namedSitemapUrl(string $sitemap, array $query = []): string
    {
        return route('api.sitemaps.show', [
            'sitemap' => $sitemap,
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function sitemapUrl(array $query = []): string
    {
        return route('api.sitemap', [
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }

    private function createPublishedContent(
        string $slug,
        Block $block,
        array $content,
        string $languageIso = 'en',
        ?Content $i18nParent = null,
    ): Content {
        $model = new Content;
        $model->forceFill([
            'block_id' => $block->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
            'settings' => [],
        ]);
        $model->id = strtolower((string) Str::ulid());

        $version = ContentVersion::createWithContentContext([
            'content_id' => $model->id,
            'content' => $content,
            'published_at' => now(),
        ], $model->setRelation('block', $block));

        $model->current_version_id = $version->id;
        $model->published_version_id = $version->id;
        $model->published_at = $version->published_at;
        $model->save();

        return $model->fresh();
    }

    private function createDraftContent(string $slug, Block $block, array $content): Content
    {
        $model = new Content;
        $model->forceFill([
            'block_id' => $block->id,
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
        ], $model->setRelation('block', $block));

        $model->current_version_id = $version->id;
        $model->published_version_id = null;
        $model->published_at = null;
        $model->save();

        return $model->fresh();
    }
}
