<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentI18nTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => 'de'],
                ],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $auditService = Mockery::mock(AuditService::class);
        $auditService->shouldReceive('log')->andReturn(new AuditLog);
        app()->instance(AuditService::class, $auditService);

        $this->block = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
        ]);
    }

    #[Test]
    public function management_content_response_includes_language_versions_for_missing_languages(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent(
            languageIso: 'en',
            slug: 'home',
            settings: ['i18n_mode_override' => 'independent'],
            published: true,
        );
        $this->createContent(
            languageIso: 'de',
            slug: 'startseite',
            i18nParent: $canonical,
            published: false,
        );

        $response = $this->getJson(route('mgmt.contents.show', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.i18n_canonical_id', $canonical->id);
        $response->assertJsonPath('data.effective_i18n_mode', 'independent');
        $response->assertJsonCount(3, 'data.language_versions');
        $response->assertJsonFragment([
            'language_iso' => 'en',
            'exists' => true,
            'status' => 'published',
            'fallback_language' => null,
        ]);
        $response->assertJsonFragment([
            'language_iso' => 'de',
            'exists' => true,
            'status' => 'draft',
            'fallback_language' => 'en',
        ]);
        $response->assertJsonFragment([
            'language_iso' => 'fr',
            'exists' => false,
            'status' => 'missing',
            'fallback_language' => 'de',
        ]);
    }

    #[Test]
    public function saving_a_missing_language_creates_exactly_one_family_row_and_duplicate_language_is_rejected(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent(
            languageIso: 'en',
            slug: 'about',
            published: true,
        );

        $payload = [
            'name' => 'Uber Uns',
            'slug' => 'uber-uns',
            'block_id' => $this->block->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
            'content' => ['title' => 'Uber Uns'],
        ];

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.language_iso', 'de');
        $response->assertJsonPath('data.i18n_parent_id', $canonical->id);

        $this->assertEquals(
            1,
            Content::query()
                ->where('i18n_parent_id', $canonical->id)
                ->where('language_iso', 'de')
                ->count()
        );

        $duplicateResponse = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            ...$payload,
            'slug' => 'uber-uns-2',
        ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonValidationErrors(['language_iso']);
    }

    #[Test]
    public function storing_a_canonical_content_with_translations_creates_the_family_in_one_pass(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Home',
            'slug' => 'home',
            'block_id' => $this->block->id,
            'language_iso' => 'en',
            'content' => ['title' => 'Home'],
            'translations' => [
                [
                    'name' => 'Startseite',
                    'slug' => 'startseite',
                    'language_iso' => 'de',
                    'content' => ['title' => 'Startseite'],
                ],
                [
                    'name' => 'Accueil',
                    'slug' => 'accueil',
                    'language_iso' => 'fr',
                    'content' => ['title' => 'Accueil'],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.language_iso', 'en');
        $response->assertJsonCount(3, 'data.language_versions');

        $canonical = Content::query()
            ->where('slug', 'home')
            ->whereNull('i18n_parent_id')
            ->firstOrFail();

        $this->assertDatabaseHas('contents', [
            'slug' => 'startseite',
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
        ]);
        $this->assertDatabaseHas('contents', [
            'slug' => 'accueil',
            'language_iso' => 'fr',
            'i18n_parent_id' => $canonical->id,
        ]);
    }

    #[Test]
    public function storing_a_canonical_content_with_duplicate_translation_language_returns_a_prefixed_error(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Home',
            'slug' => 'home',
            'block_id' => $this->block->id,
            'language_iso' => 'en',
            'content' => ['title' => 'Home'],
            'translations' => [
                [
                    'name' => 'Startseite',
                    'slug' => 'startseite',
                    'language_iso' => 'de',
                    'content' => ['title' => 'Startseite'],
                ],
                [
                    'name' => 'Start',
                    'slug' => 'start',
                    'language_iso' => 'de',
                    'content' => ['title' => 'Start'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['translations.1.language_iso']);
    }

    #[Test]
    public function updating_a_content_family_persists_translations_in_one_pass(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent(
            languageIso: 'en',
            slug: 'about',
            published: false,
        );
        $translation = $this->createContent(
            languageIso: 'de',
            slug: 'uber-uns',
            i18nParent: $canonical,
            published: false,
        );

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'name' => 'About us',
            'content' => ['title' => 'About us'],
            'translations' => [
                [
                    'id' => $translation->id,
                    'name' => 'Uber uns',
                    'content' => ['title' => 'Uber uns'],
                ],
            ],
        ]);

        $response->assertOk();

        $canonical->refresh()->load('current_version');
        $translation->refresh()->load('current_version');

        $this->assertSame('About us', $canonical->name);
        $this->assertSame(['title' => 'About us'], $canonical->current_version?->content);
        $this->assertSame('Uber uns', $translation->name);
        $this->assertSame(['title' => 'Uber uns'], $translation->current_version?->content);
    }

    #[Test]
    public function updating_a_content_family_keeps_nested_validation_errors_prefixed(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent(
            languageIso: 'en',
            slug: 'about',
            published: false,
        );
        $translation = $this->createContent(
            languageIso: 'de',
            slug: 'uber-uns',
            i18nParent: $canonical,
            published: false,
        );

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'translations' => [
                [
                    'id' => $translation->id,
                    'language_iso' => 'en',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['translations.0.language_iso']);
    }

    private function createContent(
        string $languageIso,
        string $slug,
        ?Content $i18nParent = null,
        array $settings = [],
        bool $published = false,
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
            'content' => ['title' => ucfirst($slug)],
            'created_by_id' => $this->owner->id,
            'published_at' => $published ? now() : null,
        ]);

        $content->current_version_id = $version->id;
        $content->published_version_id = $published ? $version->id : null;
        $content->published_at = $published ? now() : null;
        $content->save();

        return $content->fresh();
    }
}
