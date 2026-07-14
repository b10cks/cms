<?php

namespace Tests\Feature\Mgmt\Content;

use App\Actions\Content\CreateContent;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Content\ContentMenuCache;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentMenuControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected Block $pageBlock;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->owner = User::factory()->create();
        $this->space = Space::withoutEvents(fn () => Space::factory()->withLive()->create([
            'settings' => [
                'default_language' => 'en',
            ],
        ]));

        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->pageBlock = $this->createBlock('page', 'Page', 'root');
    }

    #[Test]
    public function it_only_returns_non_default_content_settings_in_the_menu_payload(): void
    {
        $this->actingAs($this->owner);

        $defaultContent = $this->createContent('home', $this->pageBlock, settings: [
            'disablePreview' => false,
            'i18n_mode_override' => 'inherit',
            'restrict_child_blocks' => false,
            'child_block_whitelist' => [],
            'child_tag_whitelist' => [],
            'default_child_block' => null,
        ]);
        $customContent = $this->createContent('landing', $this->pageBlock, settings: [
            'disablePreview' => true,
        ]);

        $response = $this->getJson($this->contentMenuUrl());

        $response->assertOk();
        $this->assertSame([], $response->json("data.{$defaultContent->id}.settings"));
        $this->assertSame([
            'disablePreview' => true,
        ], $response->json("data.{$customContent->id}.settings"));
    }

    #[Test]
    public function it_invalidates_the_cached_menu_when_content_changes(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createContent('home', $this->pageBlock);
        $cache = app(ContentMenuCache::class);

        $this->getJson($this->contentMenuUrl())->assertOk();
        $initialVersion = Cache::get($cache->versionKey($this->space));

        $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'settings' => [
                'disablePreview' => true,
            ],
        ])->assertOk();

        $updatedVersion = Cache::get($cache->versionKey($this->space));

        $this->assertNotSame($initialVersion, $updatedVersion);
        $this->getJson($this->contentMenuUrl())
            ->assertOk()
            ->assertJsonPath("data.{$content->id}.settings.disablePreview", true);
    }

    #[Test]
    public function it_invalidates_the_cached_menu_when_blocks_change(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createContent('home', $this->pageBlock);
        $cache = app(ContentMenuCache::class);

        $this->getJson($this->contentMenuUrl())
            ->assertOk()
            ->assertJsonPath("data.{$content->id}.color", null);
        $initialVersion = Cache::get($cache->versionKey($this->space));

        $this->patchJson(route('mgmt.blocks.update', [
            'space' => $this->space->id,
            'block' => $this->pageBlock->id,
        ]), [
            'color' => '#112233',
        ])->assertOk();

        $updatedVersion = Cache::get($cache->versionKey($this->space));

        $this->assertNotSame($initialVersion, $updatedVersion);
        $this->getJson($this->contentMenuUrl())
            ->assertOk()
            ->assertJsonPath("data.{$content->id}.color", '#112233');
    }

    private function contentMenuUrl(): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/content-menu";
    }

    private function createBlock(string $slug, string $name, string $type): Block
    {
        return Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
        ]);
    }

    private function createContent(
        string $slug,
        Block $block,
        array $settings = [],
    ): Content {
        $content = new Content();

        app(CreateContent::class)->execute([
            'block_id' => $block->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'language_iso' => 'en',
            'content' => ['title' => ucfirst($slug)],
            'settings' => $settings,
        ], $content, $this->space, $this->owner);

        return $content->fresh();
    }
}
