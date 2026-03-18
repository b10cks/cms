<?php

namespace Tests\Unit\Actions\Content;

use App\Actions\Content\TransformContentToSearchable;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class TransformContentToSearchableTest extends TestCase
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
            'schema' => [
                'title' => [
                    'type' => 'text',
                    'indexable' => true,
                ],
                'summary' => [
                    'type' => 'text',
                    'indexable' => true,
                ],
                'promo' => [
                    'type' => 'text',
                    'indexable' => true,
                ],
                'internal_notes' => [
                    'type' => 'text',
                    'indexable' => false,
                ],
                'blocks' => [
                    'type' => 'blocks',
                ],
            ],
        ]);

        Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Hero',
            'slug' => 'hero',
            'type' => 'nestable',
            'schema' => [
                'headline' => [
                    'type' => 'text',
                    'indexable' => true,
                ],
                'body' => [
                    'type' => 'text',
                    'indexable' => true,
                ],
            ],
        ]);
    }

    #[Test]
    public function it_uses_the_content_resolver_to_extract_searchable_text(): void
    {
        $canonical = $this->createPublishedContent('en', 'home', [
            'title' => 'English title',
            'blocks' => [
                ['block' => 'hero', 'headline' => 'Default hero headline'],
            ],
        ]);
        $this->createPublishedContent('de', 'startseite', [
            'summary' => 'German summary',
            'blocks' => [
                ['block' => 'hero', 'body' => 'German hero body'],
            ],
        ], $canonical);
        $translation = $this->createPublishedContent('de-at', 'startseite-at', [
            'promo' => 'Austrian promo',
            'internal_notes' => 'Hidden from search',
        ], $canonical);

        $searchableText = app(TransformContentToSearchable::class)->execute($translation, $this->space);

        $this->assertStringContainsString('English title', $searchableText);
        $this->assertStringContainsString('Default hero headline', $searchableText);
        $this->assertStringContainsString('German summary', $searchableText);
        $this->assertStringContainsString('German hero body', $searchableText);
        $this->assertStringContainsString('Austrian promo', $searchableText);
        $this->assertStringNotContainsString('Hidden from search', $searchableText);
    }

    #[Test]
    public function it_only_extracts_visible_indexable_fields(): void
    {
        Block::query()
            ->where('slug', 'hero')
            ->update([
                'schema' => [
                    'headline' => [
                        'type' => 'text',
                        'indexable' => true,
                    ],
                    'body' => [
                        'type' => 'text',
                        'indexable' => true,
                    ],
                    'show_secret' => [
                        'type' => 'boolean',
                    ],
                    'secret' => [
                        'type' => 'text',
                        'indexable' => true,
                        'conditions' => [
                            'mode' => 'all',
                            'rules' => [[
                                'field' => 'show_secret',
                                'operator' => 'equals',
                                'value' => true,
                            ]],
                        ],
                    ],
                ],
            ]);

        $content = $this->createPublishedContent('en', 'landing', [
            'title' => 'Landing title',
            'summary' => 'Index me',
            'internal_notes' => 'Do not index',
            'blocks' => [
                [
                    'block' => 'hero',
                    'headline' => 'Hero headline',
                    'body' => 'Hero body',
                    'show_secret' => false,
                    'secret' => 'Hidden secret',
                ],
            ],
        ]);

        $searchableText = app(TransformContentToSearchable::class)->execute($content, $this->space);

        $this->assertStringContainsString('Landing title', $searchableText);
        $this->assertStringContainsString('Index me', $searchableText);
        $this->assertStringContainsString('Hero headline', $searchableText);
        $this->assertStringContainsString('Hero body', $searchableText);
        $this->assertStringNotContainsString('Do not index', $searchableText);
        $this->assertStringNotContainsString('Hidden secret', $searchableText);
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
