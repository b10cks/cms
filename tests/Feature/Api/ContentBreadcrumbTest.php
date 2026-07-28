<?php

namespace Tests\Feature\Api;

use App\Models\Management\Space;
use App\Models\Management\Token;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * The breadcrumb endpoint resolves every level through the i18n family the way
 * the delivery of a single entry does, and it must not become a second way to
 * read the names and slugs of unreleased entries.
 */
class ContentBreadcrumbTest extends TestCase
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
                'slug_strategy' => 'prepend_translations',
                'languages' => [
                    ['code' => 'en', 'name' => 'English', 'fallback_language' => null],
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => 'en'],
                ],
            ],
        ]);
        $this->token = Token::factory()->create([
            'space_id' => $this->space->id,
            'token' => 'breadcrumb-token',
            'expires_at' => null,
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => ['title' => ['type' => 'text', 'translatable' => true]],
        ]);
    }

    #[Test]
    public function it_returns_the_trail_from_the_root_down_to_the_entry(): void
    {
        [, , $shoes] = $this->createEnglishTree();

        $response = $this->getJson($this->url($shoes->full_slug))->assertOk();

        $this->assertSame(
            ['/home', '/home/products', '/home/products/shoes'],
            collect($response->json('breadcrumb'))->pluck('full_slug')->all(),
        );
        $this->assertSame([0, 1, 2], collect($response->json('breadcrumb'))->pluck('depth')->all());
        $this->assertTrue($response->json('breadcrumb.0.is_root'));
        $this->assertTrue($response->json('breadcrumb.2.is_current'));
        $this->assertSame('page', $response->json('breadcrumb.0.block'));
        $this->assertSame(3, $response->json('meta.levels'));
        $this->assertSame($shoes->id, $response->json('meta.current_id'));
    }

    #[Test]
    public function it_resolves_every_level_into_the_requested_language(): void
    {
        [$home, $products, $shoes] = $this->createEnglishTree();

        $germanHome = $this->translate($home, 'startseite', 'Startseite');
        $germanProducts = $this->translate($products, 'produkte', 'Produkte', $germanHome);
        $germanShoes = $this->translate($shoes, 'schuhe', 'Schuhe', $germanProducts);

        $response = $this->getJson($this->url($germanShoes->full_slug, ['language' => 'de']))->assertOk();

        $this->assertSame(
            ['Startseite', 'Produkte', 'Schuhe'],
            collect($response->json('breadcrumb'))->pluck('name')->all(),
        );
        $this->assertSame(
            ['/de/startseite', '/de/startseite/produkte', '/de/startseite/produkte/schuhe'],
            collect($response->json('breadcrumb'))->pluck('path')->all(),
        );
        $this->assertSame(['de', 'de', 'de'], collect($response->json('breadcrumb'))->pluck('resolved_language_iso')->all());
        $this->assertSame([false, false, false], collect($response->json('breadcrumb'))->pluck('is_fallback')->all());
    }

    /**
     * An untranslated ancestor falls back per level rather than dropping out —
     * a trail with a hole in it is worse than one with a fallback label, as
     * long as the response says which is which.
     */
    #[Test]
    public function an_untranslated_ancestor_falls_back_and_is_flagged(): void
    {
        [$home, $products, $shoes] = $this->createEnglishTree();

        $this->translate($home, 'startseite', 'Startseite');
        // `products` is deliberately left untranslated, so the German child hangs
        // under the English parent and inherits its path.
        $germanShoes = $this->translate($shoes, 'schuhe', 'Schuhe', $products);

        $response = $this->getJson($this->url($germanShoes->full_slug, ['language' => 'de']))->assertOk();

        $this->assertSame(
            ['Startseite', 'Products', 'Schuhe'],
            collect($response->json('breadcrumb'))->pluck('name')->all(),
        );
        $this->assertSame([false, true, false], collect($response->json('breadcrumb'))->pluck('is_fallback')->all());
        $this->assertSame('en', $response->json('breadcrumb.1.resolved_language_iso'));
        // The locale segment follows the *request*, not the row that answered it.
        $this->assertSame('/de/home/products', $response->json('breadcrumb.1.path'));
    }

    #[Test]
    public function an_unpublished_ancestor_is_omitted_and_leaks_nothing(): void
    {
        [, $products, $shoes] = $this->createEnglishTree();

        $products->forceFill(['name' => 'Secret Range', 'published_at' => null])->save();

        $response = $this->getJson($this->url($shoes->full_slug))->assertOk();

        $this->assertSame(['/home', '/home/products/shoes'], collect($response->json('breadcrumb'))->pluck('full_slug')->all());
        $response->assertDontSee('Secret Range');
        // The gap is still visible in the depths, so a consumer can tell a level
        // was skipped rather than reparented.
        $this->assertSame([0, 2], collect($response->json('breadcrumb'))->pluck('depth')->all());
    }

    #[Test]
    public function unpublished_ancestors_can_be_opted_back_in(): void
    {
        [, $products, $shoes] = $this->createEnglishTree();

        $products->forceFill(['published_at' => null])->save();

        $response = $this->getJson($this->url($shoes->full_slug, ['ancestors' => 'all']))->assertOk();

        $this->assertSame(
            ['/home', '/home/products', '/home/products/shoes'],
            collect($response->json('breadcrumb'))->pluck('full_slug')->all(),
        );
        $this->assertFalse($response->json('breadcrumb.1.is_published'));
    }

    #[Test]
    public function an_unpublished_entry_is_not_reachable(): void
    {
        [, , $shoes] = $this->createEnglishTree();
        $shoes->forceFill(['published_at' => null])->save();

        $this->getJson($this->url($shoes->full_slug))->assertNotFound();
        $this->getJson($this->url($shoes->full_slug, ['vid' => 'draft']))->assertOk();
    }

    #[Test]
    public function it_can_be_addressed_by_id_and_can_drop_the_entry_itself(): void
    {
        [, $products, $shoes] = $this->createEnglishTree();

        $byId = $this->getJson($this->url($shoes->id))->assertOk();
        $this->assertSame($shoes->id, $byId->json('meta.current_id'));

        $withoutSelf = $this->getJson($this->url($shoes->full_slug, ['include_self' => 0]))->assertOk();
        $this->assertSame(['/home', '/home/products'], collect($withoutSelf->json('breadcrumb'))->pluck('full_slug')->all());
        // `is_current` marks the entry that was asked for, which is no longer in
        // the trail — not simply the deepest level left.
        $this->assertNull($withoutSelf->json('meta.current_id'));
        $this->assertNotNull($products->id);
    }

    #[Test]
    public function translations_and_content_are_opt_in(): void
    {
        [$home, , $shoes] = $this->createEnglishTree();
        $this->translate($home, 'startseite', 'Startseite');

        $lean = $this->getJson($this->url($shoes->full_slug))->assertOk();
        $lean->assertJsonMissingPath('breadcrumb.0.translations');
        $lean->assertJsonMissingPath('breadcrumb.0.content');

        $rich = $this->getJson($this->url($shoes->full_slug, ['translations' => 1, 'include_content' => 1]))->assertOk();
        $this->assertSame('de', $rich->json('breadcrumb.0.translations.0.language_iso'));
        $this->assertSame('/de/startseite', $rich->json('breadcrumb.0.translations.0.path'));
        $this->assertSame('Home', $rich->json('breadcrumb.0.content.title'));
        $this->assertSame('Shoes', $rich->json('breadcrumb.2.content.title'));
    }

    #[Test]
    public function an_unknown_version_scope_is_rejected(): void
    {
        [, , $shoes] = $this->createEnglishTree();

        $this->getJson($this->url($shoes->full_slug, ['vid' => (string) Str::ulid()]))->assertStatus(422);
        $this->getJson($this->url($shoes->full_slug, ['vid' => 'nonsense']))->assertStatus(422);
    }

    /**
     * The point of the recursive chain query: a deeper tree must not mean more
     * round trips.
     */
    #[Test]
    public function a_deeper_trail_does_not_cost_more_queries(): void
    {
        [, , $shoes] = $this->createEnglishTree();
        $deep = $shoes;
        foreach (['a', 'b', 'c', 'd'] as $segment) {
            $deep = $this->createPublished($segment, ['title' => strtoupper($segment)], parent: $deep);
        }

        // Warm anything remembered per request-independent state first.
        $this->countQueries($shoes->full_slug);

        $shallow = $this->countQueries($shoes->full_slug);
        $deeper = $this->countQueries($deep->full_slug);

        $this->assertSame($shallow, $deeper, 'The breadcrumb issues a query per level.');
    }

    private function countQueries(string $slug): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($this->url($slug))->assertOk();

        $count = \count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array{0: Content, 1: Content, 2: Content}
     */
    private function createEnglishTree(): array
    {
        $home = $this->createPublished('home', ['title' => 'Home']);
        $products = $this->createPublished('products', ['title' => 'Products'], parent: $home);
        $shoes = $this->createPublished('shoes', ['title' => 'Shoes'], parent: $products);

        return [$home, $products, $shoes];
    }

    /**
     * A translation of `$canonical`, hung under `$parent` — which is the
     * translated parent where one exists and the canonical one where it does
     * not. `full_slug` is left to the model's own saving hook, because that is
     * exactly the resolution the endpoint has to agree with.
     */
    private function translate(Content $canonical, string $slug, string $name, ?Content $parent = null): Content
    {
        return $this->createPublished(
            $slug,
            ['title' => $name],
            languageIso: 'de',
            i18nParentId: $canonical->id,
            parentId: $parent?->id ?? $canonical->parent_id,
            name: $name,
        );
    }

    private function createPublished(
        string $slug,
        array $content,
        ?Content $parent = null,
        string $languageIso = 'en',
        ?string $i18nParentId = null,
        ?string $parentId = null,
        ?string $name = null,
    ): Content {
        $model = new Content;
        $model->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => $name ?? Str::headline($slug),
            'slug' => $slug,
            'language_iso' => $languageIso,
            'parent_id' => $parentId ?? $parent?->id,
            'i18n_parent_id' => $i18nParentId,
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

    private function url(string $slug, array $query = []): string
    {
        return route('api.contents.breadcrumb', [
            'slug' => ltrim($slug, '/'),
            'token' => $this->token->token,
            'rv' => $this->space->updated_at->timestamp,
            ...$query,
        ]);
    }
}
