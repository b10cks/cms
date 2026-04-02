<?php

namespace Tests\Feature\Mgmt\Content;

use App\Actions\Content\CreateContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentChildSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected Block $articleBlock;

    protected Block $landingBlock;

    protected Block $singleBlock;

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
                ],
            ],
        ]);
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = $this->createBlock('page', 'Page', 'root', ['pages']);
        $this->articleBlock = $this->createBlock('article', 'Article', 'root', ['pages', 'articles']);
        $this->landingBlock = $this->createBlock('landing', 'Landing', 'universal', ['marketing']);
        $this->singleBlock = $this->createBlock('settings', 'Settings', 'single', ['config']);
    }

    #[Test]
    public function canonical_content_can_store_valid_child_content_settings(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('home', $this->pageBlock);

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'settings' => [
                'restrict_child_blocks' => true,
                'child_block_whitelist' => [$this->articleBlock->slug],
                'child_tag_whitelist' => ['marketing'],
                'default_child_block' => $this->landingBlock->id,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.restrict_child_blocks', true);
        $response->assertJsonPath('data.settings.child_block_whitelist.0', $this->articleBlock->slug);
        $response->assertJsonPath('data.settings.child_tag_whitelist.0', 'marketing');
        $response->assertJsonPath('data.settings.default_child_block', $this->landingBlock->id);
    }

    #[Test]
    public function invalid_child_content_settings_are_rejected(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('home', $this->pageBlock);

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'settings' => [
                'restrict_child_blocks' => true,
                'child_block_whitelist' => ['missing-slug'],
                'child_tag_whitelist' => ['missing-tag'],
                'default_child_block' => $this->singleBlock->id,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.child_block_whitelist.0',
            'settings.child_tag_whitelist.0',
            'settings.default_child_block',
        ]);
    }

    #[Test]
    public function default_child_block_must_match_explicit_allowlists(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('home', $this->pageBlock);

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $canonical->id,
        ]), [
            'settings' => [
                'restrict_child_blocks' => true,
                'child_block_whitelist' => [$this->articleBlock->slug],
                'child_tag_whitelist' => [],
                'default_child_block' => $this->landingBlock->id,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.default_child_block']);
    }

    #[Test]
    public function translated_content_cannot_update_child_content_settings(): void
    {
        $this->actingAs($this->owner);
        $canonical = $this->createContent('home', $this->pageBlock);
        $translation = $this->createContent('startseite', $this->pageBlock, $canonical, 'de');

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'settings' => [
                'restrict_child_blocks' => true,
                'child_block_whitelist' => [$this->articleBlock->slug],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.restrict_child_blocks',
            'settings.child_block_whitelist',
        ]);
    }

    private function createBlock(string $slug, string $name, string $type, array $tags = []): Block
    {
        return Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'tags' => $tags,
        ]);
    }

    private function createContent(
        string $slug,
        Block $block,
        ?Content $i18nParent = null,
        string $languageIso = 'en',
        array $settings = [],
        ?Content $parent = null,
    ): Content {
        $content = new Content();

        app(CreateContent::class)->execute([
            'block_id' => $block->id,
            'parent_id' => $parent?->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
            'content' => ['title' => ucfirst($slug)],
            'settings' => $settings,
        ], $content, $this->space, $this->owner);

        return $content->fresh();
    }
}
