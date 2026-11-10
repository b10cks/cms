<?php

namespace Tests\Feature\Mgmt\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Entries created in a language other than the space default: a German
 * campaign page with no English original.
 */
class LanguageOnlyContentTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $block;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
                'i18n_mode' => 'overlay',
                'languages' => [
                    ['code' => 'de', 'name' => 'German', 'fallback_language' => null],
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

        $this->actingAs($this->owner);
    }

    #[Test]
    public function an_entry_can_be_created_in_another_language(): void
    {
        $response = $this->postJson(route('mgmt.contents.store', ['space' => $this->space->id]), [
            'name' => 'Sommeraktion',
            'slug' => 'sommeraktion',
            'block_id' => $this->block->id,
            'language_iso' => 'de',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.language_iso', 'de');
        $response->assertJsonPath('data.i18n_parent_id', null);
    }

    #[Test]
    public function an_entry_created_in_another_language_gets_no_translations(): void
    {
        $campaign = $this->createContent('de', 'sommeraktion');

        $this->postJson(route('mgmt.contents.store', ['space' => $this->space->id]), [
            'name' => 'Summer sale',
            'slug' => 'summer-sale',
            'block_id' => $this->block->id,
            'language_iso' => 'en',
            'i18n_parent_id' => $campaign->id,
        ])->assertJsonValidationErrors(['i18n_parent_id']);
    }

    #[Test]
    public function a_new_entry_must_use_an_enabled_language(): void
    {
        $this->postJson(route('mgmt.contents.store', ['space' => $this->space->id]), [
            'name' => 'Campagne',
            'slug' => 'campagne',
            'block_id' => $this->block->id,
            'language_iso' => 'fr',
        ])->assertJsonValidationErrors(['language_iso']);
    }

    #[Test]
    public function the_tree_creates_an_entry_in_the_picked_language_under_the_canonical_parent(): void
    {
        $product = $this->createContent('en', 'product');
        $this->createContent('de', 'produkt', i18nParent: $product);

        $response = $this->postJson(route('mgmt.contents.tree-operations', ['space' => $this->space->id]), [
            'operations' => [[
                'type' => 'create',
                'temp_id' => 'tmp-1',
                'parent_id' => $product->id,
                'block_id' => $this->block->id,
                'name' => 'Sommeraktion',
                'slug' => 'sommeraktion',
                'language_iso' => 'de',
            ]],
        ]);

        $response->assertOk();
        $created = Content::query()->where('slug', 'sommeraktion')->sole();
        $this->assertSame('de', $created->language_iso);
        $this->assertSame($product->id, $created->parent_id);
        $this->assertSame('/produkt/sommeraktion', $created->full_slug);
    }

    #[Test]
    public function the_menu_shows_each_family_once_with_its_language(): void
    {
        $home = $this->createContent('en', 'home');
        $this->createContent('de', 'startseite', i18nParent: $home);
        $campaign = $this->createContent('de', 'sommeraktion');
        // A space whose default language changed after its entries were
        // written: the row in the new default stands for the family.
        $sale = $this->createContent('de', 'ausverkauf');
        $saleEnglish = $this->createContent('en', 'sale', i18nParent: $sale);

        $rows = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/content-menu")
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing([$home->id, $campaign->id, $saleEnglish->id], array_keys($rows));
        $this->assertSame('de', $rows[$campaign->id]['lang']);
        $this->assertSame('en', $rows[$home->id]['lang']);
    }

    #[Test]
    public function language_versions_mark_the_version_the_entry_was_created_in(): void
    {
        $campaign = $this->createContent('de', 'sommeraktion');

        $versions = collect($this->getJson(route('mgmt.contents.show', [
            'space' => $this->space->id,
            'content' => $campaign->id,
        ]))->assertOk()->json('data.language_versions'))->keyBy('language_iso');

        $this->assertFalse($versions['en']['exists']);
        $this->assertFalse($versions['en']['is_canonical']);
        $this->assertTrue($versions['de']['is_canonical']);
    }

    private function createContent(string $languageIso, string $slug, ?Content $i18nParent = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->block->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
            'settings' => [],
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::createWithContentContext([
            'content_id' => $content->id,
            'content' => [],
        ], $content->setRelation('block', $this->block));

        $content->current_version_id = $version->id;
        $content->save();

        return $content;
    }
}
