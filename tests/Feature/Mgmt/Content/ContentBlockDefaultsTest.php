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

class ContentBlockDefaultsTest extends TestCase
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
            'schema' => [
                'title' => ['type' => 'text', 'default' => 'Untitled page'],
                'subtitle' => ['type' => 'text', 'default' => ''],
                'featured' => ['type' => 'boolean', 'default' => true],
                'body' => ['type' => 'markdown'],
            ],
        ]);
    }

    #[Test]
    public function empty_content_creation_seeds_block_defaults(): void
    {
        $content = new Content;

        app(CreateContent::class)->execute([
            'block_id' => $this->pageBlock->id,
            'name' => 'Home',
            'slug' => 'home',
            'language_iso' => 'en',
        ], $content, $this->space, $this->owner);

        $stored = $content->fresh()->getCurrentContent();

        $this->assertSame('Untitled page', $stored['title'] ?? null);
        $this->assertTrue($stored['featured'] ?? null);
        $this->assertArrayNotHasKey('subtitle', $stored);
        $this->assertArrayNotHasKey('body', $stored);
    }

    #[Test]
    public function submitted_content_is_not_overridden_by_defaults(): void
    {
        $content = new Content;

        app(CreateContent::class)->execute([
            'block_id' => $this->pageBlock->id,
            'name' => 'About',
            'slug' => 'about',
            'language_iso' => 'en',
            'content' => [
                'title' => 'About us',
            ],
        ], $content, $this->space, $this->owner);

        $stored = $content->fresh()->getCurrentContent();

        $this->assertSame('About us', $stored['title'] ?? null);
    }

    #[Test]
    public function content_created_via_the_api_seeds_block_defaults(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'block_id' => $this->pageBlock->id,
            'name' => 'Landing',
            'slug' => 'landing',
        ]);

        $response->assertCreated();

        $stored = Content::query()->findOrFail($response->json('data.id'))->getCurrentContent();

        $this->assertSame('Untitled page', $stored['title'] ?? null);
    }
}
