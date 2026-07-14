<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentPublishingVersioningTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'summary' => [
                    'type' => 'text',
                    'name' => 'Summary',
                    'required' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['summary'],
            ]],
        ]);
    }

    #[Test]
    public function update_without_content_keeps_the_existing_draft_version(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $originalCurrentVersionId = $content->current_version_id;

        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'name' => 'Updated name',
        ])->assertOk();

        $content->refresh()->load('current_version');

        $this->assertSame(2, $content->versions()->count());
        $this->assertSame($originalCurrentVersionId, $content->current_version_id);
        $this->assertSame(['summary' => 'Draft summary'], $content->current_version->content);
    }

    #[Test]
    public function publish_without_content_reuses_the_current_draft_version(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $draftVersionId = $content->current_version_id;

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [])->assertOk();

        $content->refresh()->load(['current_version', 'published_version']);

        $this->assertSame(2, $content->versions()->count());
        $this->assertSame($draftVersionId, $content->current_version_id);
        $this->assertSame($draftVersionId, $content->published_version_id);
        $this->assertSame(['summary' => 'Draft summary'], $content->published_version->content);
        $this->assertNotNull($content->current_version->published_at);
    }

    #[Test]
    public function publish_with_identical_content_does_not_create_another_version(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
        );
        $publishedVersionId = $content->current_version_id;

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'content' => ['summary' => 'Published summary'],
        ])->assertOk();

        $content->refresh();

        $this->assertSame(1, $content->versions()->count());
        $this->assertSame($publishedVersionId, $content->current_version_id);
        $this->assertSame($publishedVersionId, $content->published_version_id);
    }

    #[Test]
    public function publish_uses_the_requested_historical_publish_date(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $publishedAt = '2024-05-01T12:30:00+00:00';

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'published_at' => $publishedAt,
        ])->assertOk();

        $content->refresh()->load(['current_version', 'published_version']);

        $this->assertTrue($content->published_at?->equalTo(Carbon::parse($publishedAt)));
        $this->assertTrue($content->published_version?->published_at?->equalTo(Carbon::parse($publishedAt)) ?? false);
    }

    #[Test]
    public function publish_allows_a_nullable_published_at_payload(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'published_at' => null,
        ])->assertOk();

        $content->refresh()->load(['current_version', 'published_version']);

        $this->assertNotNull($content->published_at);
        $this->assertNotNull($content->published_version?->published_at);
    }

    #[Test]
    public function publish_can_publish_a_canonical_content_with_translations_in_one_pass(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $translation = $this->createVersionedContent(
            publishedContent: ['summary' => 'Veroeffentlicht'],
            currentContent: ['summary' => 'Entwurf'],
            languageIso: 'de',
            i18nParent: $canonical,
        );

        $canonicalPublishedAt = '2024-06-01T09:00:00+00:00';
        $translationPublishedAt = '2024-06-01T09:05:00+00:00';

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'published_at' => $canonicalPublishedAt,
            'translations' => [
                [
                    'id' => $translation->id,
                    'content' => ['summary' => 'Entwurf'],
                    'published_at' => $translationPublishedAt,
                ],
            ],
        ])->assertOk();

        $canonical->refresh()->load('published_version');
        $translation->refresh()->load('published_version');

        $this->assertTrue($canonical->published_at?->equalTo(Carbon::parse($canonicalPublishedAt)));
        $this->assertTrue($translation->published_at?->equalTo(Carbon::parse($translationPublishedAt)));
        $this->assertSame(['summary' => 'Entwurf'], $translation->published_version?->content);
    }

    #[Test]
    public function publish_with_translations_allows_nullable_published_at_values(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $translation = $this->createVersionedContent(
            publishedContent: ['summary' => 'Veroeffentlicht'],
            currentContent: ['summary' => 'Entwurf'],
            languageIso: 'de',
            i18nParent: $canonical,
        );

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'published_at' => null,
            'translations' => [
                [
                    'id' => $translation->id,
                    'content' => ['summary' => 'Entwurf'],
                    'published_at' => null,
                ],
            ],
        ])->assertOk();

        $canonical->refresh()->load('published_version');
        $translation->refresh()->load('published_version');

        $this->assertNotNull($canonical->published_at);
        $this->assertNotNull($translation->published_at);
        $this->assertSame(['summary' => 'Entwurf'], $translation->published_version?->content);
    }

    #[Test]
    public function publish_with_translations_keeps_nested_validation_errors_prefixed(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $translation = $this->createVersionedContent(
            publishedContent: ['summary' => 'Veroeffentlicht'],
            currentContent: ['summary' => 'Entwurf'],
            languageIso: 'de',
            i18nParent: $canonical,
        );

        $response = $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'translations' => [
                [
                    'id' => $translation->id,
                    'published_at' => 'not-a-date',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['translations.0.published_at']);
    }

    private function createVersionedContent(
        array $publishedContent,
        ?array $currentContent = null,
        string $languageIso = 'en',
        ?Content $i18nParent = null,
    ): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->pageBlock->id,
            'name' => 'Page',
            'slug' => strtolower((string) Str::random(8)),
            'full_slug' => '/' . strtolower((string) Str::random(8)),
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());

        $publishedVersion = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $publishedContent,
            'created_by_id' => $this->owner->id,
            'published_by_id' => $this->owner->id,
            'published_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);

        $currentVersion = $publishedVersion;

        if ($currentContent !== null) {
            $currentVersion = ContentVersion::query()->forceCreate([
                'content_id' => $content->id,
                'parent_id' => $publishedVersion->id,
                'content' => $currentContent,
                'created_by_id' => $this->owner->id,
            ]);
        }

        $content->current_version_id = $currentVersion->id;
        $content->published_version_id = $publishedVersion->id;
        $content->published_at = $publishedVersion->published_at;
        $content->first_published_at = $publishedVersion->published_at;
        $content->save();

        return $content->fresh();
    }
}
