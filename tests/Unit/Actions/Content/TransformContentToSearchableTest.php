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
        ], $canonical);

        $searchableText = app(TransformContentToSearchable::class)->execute($translation, $this->space);

        $this->assertStringContainsString('English title', $searchableText);
        $this->assertStringContainsString('Default hero headline', $searchableText);
        $this->assertStringContainsString('German summary', $searchableText);
        $this->assertStringContainsString('German hero body', $searchableText);
        $this->assertStringContainsString('Austrian promo', $searchableText);
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
