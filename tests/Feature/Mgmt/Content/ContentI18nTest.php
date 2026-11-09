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

    #[Test]
    public function content_index_lists_rows_whose_language_is_no_longer_enabled(): void
    {
        $this->actingAs($this->owner);

        // 'es' is not a space language, so each row resolves to itself as the
        // canonical. Two rows, because Eloquent only flags lazy loads on
        // models that were fetched together with others.
        $this->createContent(languageIso: 'es', slug: 'inicio', published: true);
        $this->createContent(languageIso: 'es', slug: 'contacto', published: true);

        $response = $this->getJson(route('mgmt.contents.index', [
            'space' => $this->space->id,
            'sort' => '+slug',
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.content.title', 'Contacto');
        $response->assertJsonPath('data.1.content.title', 'Inicio');
    }

    #[Test]
    public function saving_an_overlay_translation_stores_the_canonical_block_type_of_a_nested_item(): void
    {
        $this->actingAs($this->owner);

        foreach (['carousel' => 'products', 'productCarousel' => 'limit'] as $slug => $ownField) {
            Block::query()->create([
                'external_id' => (string) Str::uuid(),
                'name' => ucfirst($slug),
                'slug' => $slug,
                'type' => 'nestable',
                'schema' => [
                    'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                    $ownField => ['type' => 'text', 'name' => ucfirst($ownField)],
                ],
                'editor' => [['header' => 'General', 'items' => ['title', $ownField]]],
            ]);
        }
        $this->block->update([
            'schema' => ['body' => ['type' => 'blocks', 'name' => 'Body']],
            'editor' => [['header' => 'General', 'items' => ['body']]],
        ]);
        $canonical = $this->createContent(languageIso: 'en', slug: 'studio');
        $canonical->current_version->forceFill(['content' => ['body' => [
            ['id' => 'item-1', 'block' => 'carousel', 'title' => 'Picks', 'products' => 'a,b,c'],
        ]]])->save();

        // An editor still holding the copy taken while the item was a productCarousel.
        $response = $this->postJson(route('mgmt.contents.store', ['space' => $this->space->id]), [
            'name' => 'Studio',
            'slug' => 'studio-de',
            'block_id' => $this->block->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
            'content' => ['body' => [
                ['id' => 'item-1', 'block' => 'productCarousel', 'title' => 'Auswahl', 'limit' => '12'],
            ]],
        ]);

        $response->assertCreated();
        $stored = ContentVersion::query()->findOrFail($response->json('data.current_version_id'))->content;
        $this->assertSame([['id' => 'item-1', 'title' => 'Auswahl', 'block' => 'carousel']], $stored['body']);
    }

    #[Test]
    public function a_translation_created_under_the_canonical_parent_is_filed_under_the_parents_translation(): void
    {
        $this->actingAs($this->owner);

        $enParent = $this->createContent(languageIso: 'en', slug: 'about');
        $deParent = $this->createContent(languageIso: 'de', slug: 'ueber-uns', i18nParent: $enParent);
        $enChild = $this->createContent(languageIso: 'en', slug: 'team', parent: $enParent);

        // The localization page sends the canonical entry's parent.
        $response = $this->postJson(route('mgmt.contents.store', ['space' => $this->space->id]), [
            'name' => 'Mannschaft',
            'slug' => 'mannschaft',
            'block_id' => $this->block->id,
            'language_iso' => 'de',
            'parent_id' => $enParent->id,
            'i18n_parent_id' => $enChild->id,
            'content' => ['title' => 'Mannschaft'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.parent_id', $deParent->id);
        $response->assertJsonPath('data.full_slug', '/ueber-uns/mannschaft');
    }

    #[Test]
    public function creating_a_parents_translation_adopts_the_translations_waiting_under_the_canonical_parent(): void
    {
        $this->actingAs($this->owner);

        $enParent = $this->createContent(languageIso: 'en', slug: 'about');
        $enChild = $this->createContent(languageIso: 'en', slug: 'team', parent: $enParent);
        $deChild = $this->createContent(languageIso: 'de', slug: 'mannschaft', i18nParent: $enChild, parent: $enParent);
        $frChild = $this->createContent(languageIso: 'fr', slug: 'equipe', i18nParent: $enChild, parent: $enParent);

        $response = $this->postJson(route('mgmt.contents.store', ['space' => $this->space->id]), [
            'name' => 'Ueber uns',
            'slug' => 'ueber-uns',
            'block_id' => $this->block->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $enParent->id,
            'content' => ['title' => 'Ueber uns'],
        ]);

        $response->assertCreated();
        $deChild->refresh();
        $this->assertSame($response->json('data.id'), $deChild->parent_id);
        $this->assertSame('/ueber-uns/mannschaft', $deChild->full_slug);
        $this->assertSame($enParent->id, $frChild->fresh()->parent_id);
        $this->assertSame($enParent->id, $enChild->fresh()->parent_id);
    }

    #[Test]
    public function moving_a_translation_under_a_canonical_parent_files_it_under_the_parents_translation(): void
    {
        $this->actingAs($this->owner);

        $enParent = $this->createContent(languageIso: 'en', slug: 'about');
        $deParent = $this->createContent(languageIso: 'de', slug: 'ueber-uns', i18nParent: $enParent);
        $enChild = $this->createContent(languageIso: 'en', slug: 'team');
        $deChild = $this->createContent(languageIso: 'de', slug: 'mannschaft', i18nParent: $enChild);

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $deChild->id,
        ]), ['parent_id' => $enParent->id]);

        $response->assertOk();
        $this->assertSame($deParent->id, $deChild->fresh()->parent_id);
    }

    #[Test]
    public function a_translation_slug_must_be_unique_among_the_siblings_it_will_be_filed_with(): void
    {
        $this->actingAs($this->owner);

        $enParent = $this->createContent(languageIso: 'en', slug: 'about');
        $deParent = $this->createContent(languageIso: 'de', slug: 'ueber-uns', i18nParent: $enParent);
        $enChild = $this->createContent(languageIso: 'en', slug: 'team', parent: $enParent);
        $enCrew = $this->createContent(languageIso: 'en', slug: 'crew', parent: $enParent);
        $this->createContent(languageIso: 'de', slug: 'mannschaft', i18nParent: $enCrew, parent: $deParent);

        $response = $this->postJson(route('mgmt.contents.store', ['space' => $this->space->id]), [
            'name' => 'Mannschaft',
            'slug' => 'mannschaft',
            'block_id' => $this->block->id,
            'language_iso' => 'de',
            'parent_id' => $enParent->id,
            'i18n_parent_id' => $enChild->id,
            'content' => ['title' => 'Mannschaft'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    #[Test]
    public function moving_an_entry_moves_its_translations_under_the_destinations_translations(): void
    {
        $this->actingAs($this->owner);

        $enParent = $this->createContent(languageIso: 'en', slug: 'about');
        $deParent = $this->createContent(languageIso: 'de', slug: 'ueber-uns', i18nParent: $enParent);
        $enItem = $this->createContent(languageIso: 'en', slug: 'team');
        $deItem = $this->createContent(languageIso: 'de', slug: 'mannschaft', i18nParent: $enItem);
        $frItem = $this->createContent(languageIso: 'fr', slug: 'equipe', i18nParent: $enItem);

        $this->postJson(route('mgmt.contents.move', [
            'space' => $this->space->id,
            'content' => $enItem->id,
        ]), ['parent_id' => $enParent->id])->assertOk();

        $this->assertSame($enParent->id, $enItem->fresh()->parent_id);
        $this->assertSame($deParent->id, $deItem->fresh()->parent_id);
        $this->assertSame('/ueber-uns/mannschaft', $deItem->fresh()->full_slug);
        $this->assertSame($enParent->id, $frItem->fresh()->parent_id);
    }

    private function createContent(
        string $languageIso,
        string $slug,
        ?Content $i18nParent = null,
        array $settings = [],
        bool $published = false,
        ?Content $parent = null,
    ): Content {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->block->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
            'parent_id' => $parent?->id,
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
