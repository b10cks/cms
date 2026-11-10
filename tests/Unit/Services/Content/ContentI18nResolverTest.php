<?php

namespace Tests\Unit\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Content\ContentI18nResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentI18nResolverTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected Space $space;

    protected Block $block;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                    ['code' => 'de-at', 'name' => 'Austrian German', 'fallback_language' => 'de'],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => 'de-at'],
                ],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->block = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
        ]);
    }

    #[Test]
    public function relations_are_only_hydrated_when_requested_without_changing_content_or_links(): void
    {
        $target = $this->createPublishedContent('en', 'target', ['title' => 'Target']);
        $page = $this->createPublishedContent('en', 'page', ['title' => 'Page']);
        $page->published_version->forceFill([
            'relation_ids' => [$target->id],
            'link_ids' => [$target->id],
        ])->saveQuietly();
        $connection = $page->getConnection();

        foreach (['published', 'current', $page->published_version_id] as $scope) {
            $resolve = function (bool $withRelations) use ($page, $scope, $connection): array {
                $connection->flushQueryLog();
                $connection->enableQueryLog();

                try {
                    $result = app(ContentI18nResolver::class)->resolveMany(
                        $this->space,
                        collect([$page->fresh()]),
                        $scope,
                        $withRelations,
                    )->first();

                    return [$result, count($connection->getQueryLog())];
                } finally {
                    $connection->disableQueryLog();
                }
            };

            [$full, $fullQueries] = $resolve(true);
            [$limited, $limitedQueries] = $resolve(false);

            $this->assertSame([$target->id], $full->effectiveRelations->pluck('id')->all());
            $this->assertTrue($limited->effectiveRelations->isEmpty());
            $this->assertFalse($limited->targetVersion->relationLoaded('relations'));
            $this->assertSame($full->effectiveContent, $limited->effectiveContent);
            $this->assertSame($full->effectiveLinks->pluck('id')->all(), $limited->effectiveLinks->pluck('id')->all());
            $this->assertSame($fullQueries - 1, $limitedQueries);
        }
    }

    #[Test]
    public function overlay_resolution_merges_the_available_fallback_chain(): void
    {
        $canonical = $this->createPublishedContent('en', 'home', ['title' => 'English title']);
        $this->createPublishedContent('de', 'startseite', ['body' => 'German body'], $canonical);
        $this->createPublishedContent('de-at', 'startseite-at', ['teaser' => 'Austrian teaser'], $canonical);
        $this->createPublishedContent('fr', 'accueil', ['headline' => 'French headline'], $canonical);

        $resolved = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'fr', 'published');

        $this->assertSame('overlay', $resolved->effectiveMode);
        $this->assertSame('fr', $resolved->targetContent?->language_iso);
        $this->assertSame('de-at', $resolved->fallbackContent?->language_iso);
        $this->assertSame([
            'title' => 'English title',
            'body' => 'German body',
            'teaser' => 'Austrian teaser',
            'headline' => 'French headline',
        ], $resolved->effectiveContent);
    }

    #[Test]
    public function overlay_resolution_skips_missing_chain_hops_and_uses_the_next_available_fallback(): void
    {
        $canonical = $this->createPublishedContent('en', 'home', ['title' => 'English title']);
        $this->createPublishedContent('de', 'startseite', ['body' => 'German body'], $canonical);

        $resolved = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'fr', 'published');

        $this->assertSame('overlay', $resolved->effectiveMode);
        $this->assertNull($resolved->targetContent);
        $this->assertSame('de', $resolved->fallbackContent?->language_iso);
        $this->assertSame([
            'title' => 'English title',
            'body' => 'German body',
        ], $resolved->effectiveContent);
    }

    #[Test]
    public function independent_resolution_does_not_fallback_for_missing_languages(): void
    {
        $canonical = $this->createPublishedContent(
            'en',
            'about',
            ['title' => 'English title'],
            null,
            ['i18n_mode_override' => 'independent']
        );
        $this->createPublishedContent('de', 'uber-uns', ['title' => 'German title'], $canonical);

        $resolved = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'fr', 'published');

        $this->assertSame('independent', $resolved->effectiveMode);
        $this->assertNull($resolved->targetContent);
        $this->assertNull($resolved->fallbackContent);
        $this->assertSame([], $resolved->effectiveContent);
    }

    #[Test]
    public function overlay_resolution_merges_translatable_table_cells_by_row_id(): void
    {
        $this->block->update([
            'schema' => [
                'roster' => [
                    'type' => 'table',
                    'name' => 'Roster',
                    'translatable' => true,
                    'has_thead' => true,
                    'min' => null,
                    'max' => null,
                    'columns' => [
                        ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                        [
                            'key' => 'status',
                            'label' => 'Status',
                            'type' => 'option',
                            'source' => 'self',
                            'options' => [
                                ['name' => 'Draft', 'value' => 'draft'],
                                ['name' => 'Review', 'value' => 'review'],
                            ],
                            'data_source_id' => null,
                        ],
                        ['key' => 'active', 'label' => 'Active', 'type' => 'boolean'],
                    ],
                    'default' => [
                        'header' => ['title' => 'Title', 'status' => 'Status', 'active' => 'Active'],
                        'rows' => [],
                    ],
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['roster'],
            ]],
        ]);

        $canonical = $this->createPublishedContent('en', 'team', [
            'roster' => [
                'header' => [
                    'title' => 'Title',
                    'status' => 'Status',
                    'active' => 'Active',
                ],
                'rows' => [
                    [
                        'id' => 'row-b',
                        'cells' => [
                            'title' => 'Beta',
                            'status' => 'review',
                            'active' => false,
                        ],
                    ],
                    [
                        'id' => 'row-a',
                        'cells' => [
                            'title' => 'Alpha',
                            'status' => 'draft',
                            'active' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $this->createPublishedContent('de', 'team-de', [
            'roster' => [
                'header' => [
                    'title' => 'Titel',
                ],
                'rows' => [
                    [
                        'id' => 'row-a',
                        'cells' => [
                            'title' => 'Alfa',
                            'status' => 'review',
                            'active' => false,
                        ],
                    ],
                    [
                        'id' => 'row-extra',
                        'cells' => [
                            'title' => 'Ignored row',
                        ],
                    ],
                ],
            ],
        ], $canonical);

        $resolved = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'fr', 'published');

        $this->assertSame([
            'header' => [
                'title' => 'Titel',
                'status' => 'Status',
                'active' => 'Active',
            ],
            'rows' => [
                [
                    'id' => 'row-b',
                    'cells' => [
                        'title' => 'Beta',
                        'status' => 'review',
                        'active' => false,
                    ],
                ],
                [
                    'id' => 'row-a',
                    'cells' => [
                        'title' => 'Alfa',
                        'status' => 'draft',
                        'active' => true,
                    ],
                ],
            ],
        ], $resolved->effectiveContent['roster']);
    }

    #[Test]
    public function overlay_resolution_keeps_the_canonical_block_type_of_a_nested_item(): void
    {
        $this->createNestedBlock('carousel', [
            'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
            'products' => ['type' => 'text', 'name' => 'Products'],
        ]);
        $this->createNestedBlock('productCarousel', [
            'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
            'limit' => ['type' => 'number', 'name' => 'Limit'],
        ]);
        $this->block->update([
            'schema' => ['body' => ['type' => 'blocks', 'name' => 'Body']],
            'editor' => [['header' => 'General', 'items' => ['body']]],
        ]);

        $canonical = $this->createPublishedContent('en', 'studio', ['body' => [
            ['id' => 'item-1', 'block' => 'carousel', 'title' => 'Picks', 'products' => 'a,b,c'],
        ]]);
        // A copy taken while the item was still a productCarousel.
        $this->createPublishedContent('de', 'studio-de', ['body' => [
            ['id' => 'item-1', 'block' => 'productCarousel', 'title' => 'Auswahl', 'limit' => 12],
        ]], $canonical);

        $resolved = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'de', 'published');

        $this->assertSame([
            ['id' => 'item-1', 'block' => 'carousel', 'title' => 'Auswahl', 'products' => 'a,b,c'],
        ], $resolved->effectiveContent['body']);
    }

    #[Test]
    public function overlay_resolution_never_gives_a_nested_item_another_items_translation(): void
    {
        $this->createNestedBlock('teaser', [
            'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
        ]);
        $this->block->update([
            'schema' => ['body' => ['type' => 'blocks', 'name' => 'Body']],
            'editor' => [['header' => 'General', 'items' => ['body']]],
        ]);

        $canonical = $this->createPublishedContent('en', 'home', ['body' => [
            ['id' => 'item-a', 'block' => 'teaser', 'title' => 'One'],
            ['id' => 'item-b', 'block' => 'teaser', 'title' => 'Two'],
        ]]);
        // Only the second item is translated, and it sits at index 0.
        $this->createPublishedContent('de', 'start', ['body' => [
            ['id' => 'item-b', 'block' => 'teaser', 'title' => 'Zwei'],
        ]], $canonical);

        $resolved = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'de', 'published');

        $this->assertSame([
            ['id' => 'item-a', 'block' => 'teaser', 'title' => 'One'],
            ['id' => 'item-b', 'block' => 'teaser', 'title' => 'Zwei'],
        ], $resolved->effectiveContent['body']);
    }

    #[Test]
    public function the_published_scope_ignores_unpublished_family_rows(): void
    {
        $canonical = $this->createPublishedContent('en', 'about', ['title' => 'About']);
        $withdrawn = $this->createPublishedContent('de', 'ueber-uns', ['title' => 'Zurückgezogen'], $canonical);
        $withdrawn->forceFill(['published_at' => null])->save();

        $resolved = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'de', 'published');

        $this->assertNull($resolved->targetContent);
        $this->assertSame($canonical->id, $resolved->resolvedRow->id);
        $this->assertSame(['title' => 'About'], $resolved->effectiveContent);

        // de-at falls back to de, which may not contribute as a layer either.
        $viaFallback = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'de-at', 'published');

        $this->assertSame($canonical->id, $viaFallback->fallbackContent?->id);
        $this->assertSame(['title' => 'About'], $viaFallback->effectiveContent);

        $draft = app(ContentI18nResolver::class)->resolve($this->space, $canonical, 'de', 'current');

        $this->assertSame($withdrawn->id, $draft->targetContent?->id);
    }

    private function createNestedBlock(string $slug, array $schema): void
    {
        Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => 'nestable',
            'schema' => $schema,
            'editor' => [['header' => 'General', 'items' => array_keys($schema)]],
        ]);
    }

    private function createPublishedContent(
        string $languageIso,
        string $slug,
        array $contentData,
        ?Content $i18nParent = null,
        array $settings = [],
    ): Content {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->block->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
            'settings' => $settings,
        ]);

        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $contentData,
            'created_by_id' => $this->owner->id,
            'published_at' => now(),
        ]);

        $content->current_version_id = $version->id;
        $content->published_version_id = $version->id;
        $content->published_at = now();
        $content->save();

        return $content->fresh();
    }
}
