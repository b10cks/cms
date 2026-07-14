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

class ContentCacheSettingsTest extends TestCase
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
        ]);
    }

    #[Test]
    public function content_can_store_cache_settings(): void
    {
        $this->actingAs($this->owner);
        $content = $this->createContent('home');

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'settings' => [
                'cache_ttl' => 3600,
                'cache_tags' => ['news', 'home'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.cache_ttl', 3600);
        $response->assertJsonPath('data.settings.cache_tags', ['news', 'home']);
    }

    #[Test]
    public function invalid_cache_settings_are_rejected(): void
    {
        $this->actingAs($this->owner);
        $content = $this->createContent('home');

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'settings' => [
                'cache_ttl' => -5,
                'cache_tags' => ['inva lid tag', str_repeat('a', 65)],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.cache_ttl',
            'settings.cache_tags.0',
            'settings.cache_tags.1',
        ]);
    }

    #[Test]
    public function duplicate_cache_tags_are_rejected(): void
    {
        $this->actingAs($this->owner);
        $content = $this->createContent('home');

        $response = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $content->id,
        ]), [
            'settings' => [
                'cache_tags' => ['news', 'news'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.cache_tags.0']);
    }

    private function createContent(string $slug): Content
    {
        $content = new Content;

        app(CreateContent::class)->execute([
            'block_id' => $this->pageBlock->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'language_iso' => 'en',
        ], $content, $this->space, $this->owner);

        return $content->fresh();
    }
}
