<?php

namespace Tests\Feature\Mgmt\Content;

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
