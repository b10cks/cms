<?php

namespace Tests\Feature\Mgmt\Content;

use App\Actions\Content\PublishContent;
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
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                ],
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

    #[Test]
    public function create_and_publish_makes_a_new_language_version_live_without_a_prior_save(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
        );

        $response = $this->postJson(route('mgmt.contents.create-publish', [
            'space' => $this->space->id,
        ]), [
            'block_id' => $this->pageBlock->id,
            'name' => 'Seite',
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
            'content' => ['summary' => 'Entwurf'],
            'message' => 'Direkt veroeffentlicht',
        ])->assertCreated();

        $created = Content::query()->findOrFail($response->json('data.id'));
        $created->load(['current_version', 'published_version']);

        $this->assertSame('de', $created->language_iso);
        $this->assertSame($canonical->id, $created->i18n_parent_id);
        $this->assertNotNull($created->published_at);
        $this->assertNotNull($created->first_published_at);
        // One version, published — not a draft plus a published copy.
        $this->assertSame($created->current_version_id, $created->published_version_id);
        $this->assertSame(1, ContentVersion::query()->where('content_id', $created->id)->count());
        $this->assertSame(['summary' => 'Entwurf'], $created->published_version?->content);
        $this->assertSame('Direkt veroeffentlicht', $created->published_version?->message);
    }

    #[Test]
    public function update_with_a_stale_parent_version_id_conflicts(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );

        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'parent_version_id' => (string) Str::ulid(),
            'content' => ['summary' => 'Stale write'],
        ])->assertStatus(409);

        $content->refresh()->load('current_version');

        $this->assertSame(['summary' => 'Draft summary'], $content->current_version?->content);
    }

    #[Test]
    public function update_with_a_matching_parent_version_id_succeeds(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );

        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'parent_version_id' => $content->current_version_id,
            'content' => ['summary' => 'Matching write'],
        ])->assertOk();

        $content->refresh()->load('current_version');

        $this->assertSame(['summary' => 'Matching write'], $content->current_version?->content);
    }

    #[Test]
    public function update_without_a_parent_version_id_is_last_write_wins(): void
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
            'content' => ['summary' => 'First write'],
        ])->assertOk();

        $content->refresh();
        $this->assertNotSame($originalCurrentVersionId, $content->current_version_id);

        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'content' => ['summary' => 'Second write'],
        ])->assertOk();

        $content->refresh()->load('current_version');

        $this->assertSame(['summary' => 'Second write'], $content->current_version?->content);
    }

    #[Test]
    public function publish_branches_from_the_committed_version_not_the_one_it_was_resolved_with(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createVersionedContent(
            publishedContent: ['summary' => 'Published summary'],
            currentContent: ['summary' => 'Draft summary'],
        );
        $staleModel = Content::query()->findOrFail($content->id);

        // A concurrent update advances the pointer after publish resolved its model.
        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'content' => ['summary' => 'Concurrent draft'],
        ])->assertOk();
        $concurrentVersionId = $content->refresh()->current_version_id;

        app(PublishContent::class)->execute(
            ['content' => ['summary' => 'Published from stale model']],
            $staleModel,
            $this->space,
            $this->owner,
        );

        $content->refresh()->load('published_version');

        $this->assertSame($concurrentVersionId, $content->published_version?->parent_id);
        $this->assertSame(['summary' => 'Published from stale model'], $content->published_version?->content);
    }

    #[Test]
    public function create_and_publish_leaves_nothing_behind_when_publish_validation_fails(): void
    {
        $this->actingAs($this->owner);

        $before = Content::query()->count();

        // `summary` is required, so publish-mode validation rejects this.
        $this->postJson(route('mgmt.contents.create-publish', [
            'space' => $this->space->id,
        ]), [
            'block_id' => $this->pageBlock->id,
            'name' => 'Seite',
            'language_iso' => 'de',
            'content' => [],
        ])->assertStatus(422);

        $this->assertSame($before, Content::query()->count());
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
