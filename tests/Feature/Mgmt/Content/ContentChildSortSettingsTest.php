<?php

namespace Tests\Feature\Mgmt\Content;

use App\Actions\Content\CreateContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentChildSortSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AuditService::class, function ($mock): void {
            $mock->shouldReceive('log')->andReturn(new AuditLog);
        });

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

        $this->pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'tags' => ['pages'],
        ]);
    }

    #[Test]
    public function canonical_content_can_store_child_sorting_settings(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('news', $this->pageBlock);

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'settings' => [
                'child_sort_by' => 'published_at',
                'child_sort_direction' => 'desc',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.child_sort_by', 'published_at');
        $response->assertJsonPath('data.settings.child_sort_direction', 'desc');
    }

    #[Test]
    public function canonical_content_can_sort_children_by_a_first_level_content_field(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('news', $this->pageBlock);

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'settings' => [
                'child_sort_by' => 'content.publishDate',
                'child_sort_direction' => 'desc',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.child_sort_by', 'content.publishDate');
    }

    #[Test]
    public function invalid_content_field_sort_keys_are_rejected(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('news', $this->pageBlock);

        foreach (['content.', 'content.bad-field', 'content.a.b', 'content.a b'] as $invalid) {
            $response = $this->patchJson(route('mgmt.contents.update', [
                'space' => $this->space->id,
                'content' => $canonical->id,
            ]), [
                'settings' => ['child_sort_by' => $invalid],
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['settings.child_sort_by']);
        }
    }

    #[Test]
    public function content_menu_exposes_sort_values_only_for_content_field_sorted_folders(): void
    {
        $this->actingAs($this->owner);
        $folder = $this->createContent('news', $this->pageBlock, settings: [
            'child_sort_by' => 'content.publishDate',
            'child_sort_direction' => 'desc',
        ]);
        $child = $this->createContent('article', $this->pageBlock, parent: $folder, content: [
            'publishDate' => '2026-02-11',
        ]);
        $unrelated = $this->createContent('about', $this->pageBlock);

        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/content-menu");

        $response->assertOk();
        $response->assertJsonPath("data.{$child->id}.sv", '2026-02-11');
        $this->assertArrayNotHasKey('sv', $response->json("data.{$unrelated->id}"));
        $this->assertArrayNotHasKey('sv', $response->json("data.{$folder->id}"));
    }

    #[Test]
    public function invalid_child_sorting_settings_are_rejected(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('news', $this->pageBlock);

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'settings' => [
                'child_sort_by' => 'full_slug',
                'child_sort_direction' => 'sideways',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.child_sort_by',
            'settings.child_sort_direction',
        ]);
    }

    #[Test]
    public function translated_content_cannot_update_child_sorting_settings(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('news', $this->pageBlock);
        $translation = $this->createContent('neuigkeiten', $this->pageBlock, $canonical, 'de');

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'settings' => [
                'child_sort_by' => 'published_at',
                'child_sort_direction' => 'desc',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.child_sort_by',
            'settings.child_sort_direction',
        ]);
    }

    #[Test]
    public function content_menu_exposes_created_at_and_child_sorting_settings(): void
    {
        $this->actingAs($this->owner);
        $folder = $this->createContent('news', $this->pageBlock, settings: [
            'child_sort_by' => 'published_at',
            'child_sort_direction' => 'desc',
        ]);

        $response = $this->getJson("/mgmt/v1/spaces/{$this->space->id}/content-menu");

        $response->assertOk();
        $response->assertJsonPath("data.{$folder->id}.settings.child_sort_by", 'published_at');
        $response->assertJsonPath("data.{$folder->id}.settings.child_sort_direction", 'desc');
        $this->assertNotNull($response->json("data.{$folder->id}.cat"));
    }

    private function createContent(
        string $slug,
        Block $block,
        ?Content $i18nParent = null,
        string $languageIso = 'en',
        array $settings = [],
        ?Content $parent = null,
        array $content = [],
    ): Content {
        $model = new Content;

        app(CreateContent::class)->execute([
            'block_id' => $block->id,
            'parent_id' => $parent?->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
            'content' => ['title' => ucfirst($slug), ...$content],
            'settings' => $settings,
        ], $model, $this->space, $this->owner);

        return $model->fresh();
    }
}
