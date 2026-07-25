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

class ContentMassEditTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected User $viewer;

    protected Space $space;

    protected Block $pageBlock;

    protected Block $plainBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
                    ['code' => 'fr', 'name' => 'French', 'fallback_language' => null],
                ],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->assignSpaceRole($this->space, $this->viewer, 'viewer');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => [
                    'type' => 'text',
                    'name' => 'Title',
                    'translatable' => true,
                ],
                'meta' => [
                    'type' => 'meta',
                    'name' => 'Meta',
                    'translatable' => true,
                ],
                'internal_note' => [
                    'type' => 'text',
                    'name' => 'Internal note',
                ],
            ],
        ]);

        $this->plainBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Plain',
            'slug' => 'plain',
            'type' => 'root',
            'schema' => [
                'internal_note' => [
                    'type' => 'text',
                    'name' => 'Internal note',
                ],
            ],
        ]);
    }

    #[Test]
    public function fields_endpoint_aggregates_translatable_fields_across_blocks(): void
    {
        $this->actingAs($this->owner);

        $response = $this->getJson(route('mgmt.contents.mass-edit.fields', [
            'space' => $this->space->id,
        ]));

        $response->assertOk();

        $keys = array_column($response->json('data'), 'key');

        $this->assertContains('title', $keys);
        $this->assertContains('meta', $keys);
        // Non-translatable fields of supported types are offered too (source-only).
        $this->assertContains('internal_note', $keys);

        $fields = collect($response->json('data'));
        $this->assertTrue($fields->firstWhere('key', 'title')['translatable']);
        $this->assertFalse($fields->firstWhere('key', 'internal_note')['translatable']);

        $meta = $fields->firstWhere('key', 'meta');
        $this->assertSame('meta', $meta['type']);
        $this->assertSame(['page'], array_column($meta['blocks'], 'slug'));
    }

    #[Test]
    public function fields_endpoint_requires_space_membership(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson(route('mgmt.contents.mass-edit.fields', [
            'space' => $this->space->id,
        ]))->assertForbidden();
    }

    #[Test]
    public function rows_endpoint_returns_units_for_selected_fields_and_languages(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent('home', content: [
            'title' => 'Home',
            'meta' => ['title' => 'Home | Site'],
            'internal_note' => 'not exported',
        ]);
        $this->createContent('startseite', i18nParent: $canonical, languageIso: 'de', content: [
            'title' => 'Startseite',
        ]);
        // Content of a block without any selected field must not appear at all.
        $this->createContent('plain', block: $this->plainBlock, content: [
            'internal_note' => 'x',
        ]);

        $response = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title,meta',
            'languages' => 'de',
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.content_id', $canonical->id);
        $response->assertJsonPath('data.0.full_slug', '/home');
        $response->assertJsonPath('data.0.source_language', 'en');
        $response->assertJsonPath('data.0.languages', ['de']);
        $response->assertJsonPath('meta.total', 1);

        $units = collect($response->json('data.0.units'))->keyBy('id');

        $this->assertSame('Home', $units['title']['source']);
        $this->assertSame(['de' => 'Startseite'], $units['title']['targets']);
        $this->assertSame('Home | Site', $units['meta.title']['source']);
        // Empty units are included so the grid can be filled in.
        $this->assertSame('', $units['meta.description']['source']);
        $this->assertArrayNotHasKey('internal_note', $units->all());
    }

    #[Test]
    public function rows_endpoint_supports_block_and_name_filters(): void
    {
        $this->actingAs($this->owner);

        $this->createContent('home', content: ['title' => 'Home']);
        $this->createContent('about', content: ['title' => 'About']);

        $response = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title',
            'name' => 'About',
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'About');
    }

    #[Test]
    public function non_translatable_fields_are_editable_in_the_source_language_only(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent('home', content: [
            'title' => 'Home',
            'internal_note' => 'old note',
        ]);
        $this->createContent('startseite', i18nParent: $canonical, languageIso: 'de', content: []);

        $rows = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'internal_note',
        ]));

        $rows->assertOk();
        $units = collect($rows->json('data.0.units'))->keyBy('id');
        $this->assertSame('old note', $units['internal_note']['source']);
        $this->assertFalse($units['internal_note']['translatable']);

        $response = $this->patchJson(route('mgmt.contents.mass-edit.save', [
            'space' => $this->space->id,
        ]), [
            'documents' => [[
                'content_id' => $canonical->id,
                'targets' => [
                    'en' => ['internal_note' => 'new note'],
                    'de' => ['internal_note' => 'verboten'],
                ],
            ]],
        ]);

        $response->assertOk();

        // Source-language edit lands on the canonical row.
        $this->assertSame('new note', $canonical->fresh()->getCurrentContent()['internal_note']);

        // Target-language edit is refused and reported as ignored.
        $this->assertContains('internal_note', $response->json('ignored_fields'));
        $translation = Content::query()
            ->where('i18n_parent_id', $canonical->id)
            ->where('language_iso', 'de')
            ->first();
        $this->assertArrayNotHasKey('internal_note', $translation->getCurrentContent());
    }

    #[Test]
    public function rows_endpoint_supports_operator_and_field_value_filters(): void
    {
        $this->actingAs($this->owner);

        $this->createContent('home', published: true, content: ['title' => 'Home']);
        $this->createContent('about', content: ['title' => 'About us']);

        $bySlug = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title',
            'slug' => '^like:ho',
        ]));
        $bySlug->assertOk()->assertJsonCount(1, 'data');
        $bySlug->assertJsonPath('data.0.slug', 'home');

        $byFullSlug = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title',
            'full_slug' => 'like:/abo',
        ]));
        $byFullSlug->assertOk()->assertJsonCount(1, 'data');
        $byFullSlug->assertJsonPath('data.0.slug', 'about');

        $byFieldValue = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title',
            'field_title' => 'like:bout',
        ]));
        $byFieldValue->assertOk()->assertJsonCount(1, 'data');
        $byFieldValue->assertJsonPath('data.0.slug', 'about');

        $byPublished = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title',
            'published' => 'eq:1',
        ]));
        $byPublished->assertOk()->assertJsonCount(1, 'data');
        $byPublished->assertJsonPath('data.0.slug', 'home');
    }

    #[Test]
    public function save_applies_only_the_delta_as_a_draft_on_the_language_row(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent('home', published: true, content: [
            'title' => 'Home',
            'meta' => ['title' => 'Home | Site'],
        ]);
        $translation = $this->createContent('startseite', i18nParent: $canonical, languageIso: 'de', content: [
            'title' => 'Alt',
            'meta' => ['title' => 'Alt | Site'],
        ]);

        $response = $this->patchJson(route('mgmt.contents.mass-edit.save', [
            'space' => $this->space->id,
        ]), [
            'documents' => [[
                'content_id' => $canonical->id,
                'targets' => [
                    'de' => ['title' => 'Startseite', 'meta.title' => 'Alt | Site'],
                ],
            ]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('summary.total_success', 1);

        $translation->refresh();
        $current = $translation->getCurrentContent();

        $this->assertSame('Startseite', $current['title']);
        $this->assertSame('Alt | Site', $current['meta']['title']);
        $this->assertNull($translation->published_version_id);

        // Only the actually-changed unit is reported.
        $changes = collect($response->json('changes'))->firstWhere('language', 'de');
        $this->assertSame(['title'], array_column($changes['changes'], 'field'));

        // Canonical row is untouched.
        $this->assertSame('Home', $canonical->fresh()->getCurrentContent()['title']);
    }

    #[Test]
    public function save_can_edit_the_source_language_on_the_canonical_row(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent('home', content: [
            'title' => 'Home',
            'meta' => ['title' => 'Home | Site'],
        ]);

        $this->patchJson(route('mgmt.contents.mass-edit.save', [
            'space' => $this->space->id,
        ]), [
            'documents' => [[
                'content_id' => $canonical->id,
                'targets' => [
                    'en' => ['meta.title' => 'Welcome | Site', 'title' => ''],
                ],
            ]],
        ])->assertOk();

        $current = $canonical->fresh()->getCurrentContent();

        $this->assertSame('Welcome | Site', $current['meta']['title']);
        // Empty values are intentional clears in mass edit.
        $this->assertSame('', $current['title']);
    }

    #[Test]
    public function save_with_publish_mode_publishes_the_language_row(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent('home', published: true, content: ['title' => 'Home']);
        $translation = $this->createContent('startseite', i18nParent: $canonical, languageIso: 'de', content: [
            'title' => 'Alt',
        ]);

        $this->patchJson(route('mgmt.contents.mass-edit.save', [
            'space' => $this->space->id,
        ]), [
            'documents' => [[
                'content_id' => $canonical->id,
                'targets' => ['de' => ['title' => 'Startseite']],
            ]],
            'mode' => 'publish',
        ])->assertOk();

        $translation->refresh();

        $this->assertNotNull($translation->published_version_id);
        $this->assertSame('Startseite', $translation->getContent()['title']);
    }

    #[Test]
    public function save_creates_missing_language_rows_by_default(): void
    {
        $this->actingAs($this->owner);

        $canonical = $this->createContent('home', content: ['title' => 'Home']);

        $this->patchJson(route('mgmt.contents.mass-edit.save', [
            'space' => $this->space->id,
        ]), [
            'documents' => [[
                'content_id' => $canonical->id,
                'targets' => ['fr' => ['title' => 'Accueil']],
            ]],
        ])->assertOk();

        $row = Content::query()
            ->where('i18n_parent_id', $canonical->id)
            ->where('language_iso', 'fr')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Accueil', $row->getCurrentContent()['title']);
    }

    #[Test]
    public function save_is_forbidden_for_viewers(): void
    {
        $this->actingAs($this->viewer);

        $canonical = $this->createContent('home', content: ['title' => 'Home']);

        $this->patchJson(route('mgmt.contents.mass-edit.save', [
            'space' => $this->space->id,
        ]), [
            'documents' => [[
                'content_id' => $canonical->id,
                'targets' => ['de' => ['title' => 'Startseite']],
            ]],
        ])->assertForbidden();
    }

    #[Test]
    public function export_can_be_limited_to_specific_fields(): void
    {
        $this->actingAs($this->owner);

        $this->createContent('home', content: [
            'title' => 'Home',
            'meta' => ['title' => 'Home | Site'],
        ]);

        $response = $this->postJson(route('mgmt.contents.data.export', [
            'space' => $this->space->id,
        ]), [
            'as' => 'json',
            'fields' => 'meta',
            'languages' => 'de',
        ]);

        $response->assertOk();

        $payload = json_decode($response->streamedContent(), true);
        $units = array_column($payload['documents'][0]['units'], 'field');

        $this->assertNotContains('title', $units);
        $this->assertContains('meta', $units);
        $this->assertSame(['de'], $payload['documents'][0]['languages']);
    }

    #[Test]
    public function grid_export_contains_every_item_the_list_view_returns(): void
    {
        $this->actingAs($this->owner);

        $this->createContent('home', content: ['title' => 'Home']);
        // Selected field is empty — must still be listed AND exported in grid mode.
        $this->createContent('untitled', content: []);
        // Different block without the field — in neither list nor export.
        $this->createContent('plain', block: $this->plainBlock, content: ['internal_note' => 'x']);

        $list = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title',
        ]));
        $list->assertOk();
        $listTotal = $list->json('meta.total');
        $this->assertSame(2, $listTotal);

        $gridExport = $this->postJson(route('mgmt.contents.data.export', [
            'space' => $this->space->id,
        ]), [
            'as' => 'json',
            'fields' => 'title',
            'grid' => 1,
        ]);
        $gridExport->assertOk();

        $documents = json_decode($gridExport->streamedContent(), true)['documents'];
        $this->assertCount($listTotal, $documents);
        $this->assertEqualsCanonicalizing(
            ['home', 'untitled'],
            array_column($documents, 'slug'),
        );
        $this->assertEqualsCanonicalizing(
            ['/home', '/untitled'],
            array_column($documents, 'full_slug'),
        );

        // Flat formats carry slug + full_slug as reserved columns.
        $csvExport = $this->postJson(route('mgmt.contents.data.export', [
            'space' => $this->space->id,
        ]), [
            'as' => 'csv',
            'fields' => 'title',
            'grid' => 1,
        ]);
        $csvExport->assertOk();
        $csvLines = explode("\n", trim($csvExport->streamedContent()));
        $header = str_getcsv($csvLines[0]);
        $this->assertContains('slug', $header);
        $this->assertContains('full_slug', $header);
        $this->assertCount($listTotal, \array_slice($csvLines, 1));

        // The classic translation export keeps trimming empty documents.
        $classicExport = $this->postJson(route('mgmt.contents.data.export', [
            'space' => $this->space->id,
        ]), [
            'as' => 'json',
            'fields' => 'title',
        ]);
        $classicExport->assertOk();
        $this->assertSame(
            ['home'],
            array_column(json_decode($classicExport->streamedContent(), true)['documents'], 'slug'),
        );
    }

    #[Test]
    public function fields_and_rows_reach_into_nested_block_fields(): void
    {
        $this->actingAs($this->owner);

        Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Teaser',
            'slug' => 'teaser',
            'type' => 'nestable',
            'schema' => [
                'headline' => [
                    'type' => 'text',
                    'name' => 'Headline',
                    'translatable' => true,
                ],
            ],
        ]);

        $sectionBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Section',
            'slug' => 'section',
            'type' => 'root',
            'schema' => [
                'body' => [
                    'type' => 'blocks',
                    'name' => 'Body',
                    'restrict_blocks' => true,
                    'block_whitelist' => ['teaser'],
                ],
            ],
        ]);

        $this->createContent('section', block: $sectionBlock, content: [
            'body' => [
                ['id' => 'item-1', 'block' => 'teaser', 'headline' => 'Nested headline'],
            ],
        ]);

        $fields = collect($this->getJson(route('mgmt.contents.mass-edit.fields', [
            'space' => $this->space->id,
        ]))->assertOk()->json('data'));

        $headline = $fields->firstWhere('key', 'headline');
        $this->assertNotNull($headline, 'Nested-only fields must be offered.');
        $this->assertTrue($headline['translatable']);
        $this->assertSame(['section'], array_column($headline['blocks'], 'slug'));

        $rows = $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'headline',
        ]));

        $rows->assertOk();
        $rows->assertJsonPath('meta.total', 1);
        $rows->assertJsonPath('data.0.slug', 'section');
        $this->assertSame(
            ['body.item-1.headline'],
            array_column($rows->json('data.0.units'), 'id'),
        );

        // The whitelist keeps `title` out of the section block's reachable set.
        $this->getJson(route('mgmt.contents.mass-edit.rows', [
            'space' => $this->space->id,
            'fields' => 'title',
        ]))->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function grid_export_requires_the_selected_fields(): void
    {
        $this->actingAs($this->owner);

        $this->postJson(route('mgmt.contents.data.export', [
            'space' => $this->space->id,
        ]), [
            'as' => 'json',
            'grid' => 1,
        ])->assertJsonValidationErrorFor('fields');
    }

    private function createContent(
        string $slug,
        ?Block $block = null,
        ?Content $i18nParent = null,
        string $languageIso = 'en',
        bool $published = false,
        array $content = [],
    ): Content {
        $model = new Content;
        $model->forceFill([
            'block_id' => ($block ?? $this->pageBlock)->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
        ]);
        $model->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $model->id,
            'content' => $content,
            'created_by_id' => $this->owner->id,
            'published_at' => $published ? now() : null,
        ]);

        $model->current_version_id = $version->id;
        $model->published_version_id = $published ? $version->id : null;
        $model->published_at = $published ? now() : null;
        $model->save();

        return $model->fresh();
    }
}
