<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class SpaceSitemapSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->space = Space::factory()->withLive()->create();
        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function space_settings_accept_valid_sitemap_mappings(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemap' => [
                    'types' => [
                        ['block' => 'page', 'path' => 'meta'],
                        ['block' => 'article', 'path' => 'seo.meta'],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.sitemap.types.0.block', 'page');
        $response->assertJsonPath('data.settings.sitemap.types.0.path', 'meta');
        $response->assertJsonPath('data.settings.sitemap.types.1.block', 'article');
        $response->assertJsonPath('data.settings.sitemap.types.1.path', 'seo.meta');
    }

    #[Test]
    public function space_settings_accept_valid_named_sitemaps(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemaps' => [
                    ['slug' => 'pages', 'types' => [['block' => 'page', 'path' => 'meta']]],
                    [
                        'slug' => 'news',
                        'types' => [
                            ['block' => 'article', 'path' => 'seo'],
                            ['block' => 'press-release', 'path' => 'meta'],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.settings.sitemaps.0.slug', 'pages');
        $response->assertJsonPath('data.settings.sitemaps.1.slug', 'news');
        $response->assertJsonPath('data.settings.sitemaps.1.types.0.block', 'article');
        $response->assertJsonPath('data.settings.sitemaps.1.types.0.path', 'seo');
    }

    #[Test]
    public function space_settings_reject_duplicate_sitemap_slugs_and_invalid_slug_format(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemaps' => [
                    ['slug' => 'News', 'types' => [['block' => 'article', 'path' => 'seo']]],
                    ['slug' => 'news', 'types' => [['block' => 'page', 'path' => 'meta']]],
                    ['slug' => 'no types', 'types' => []],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.sitemaps.0.slug',
            'settings.sitemaps.2.slug',
            'settings.sitemaps.2.types',
        ]);
    }

    #[Test]
    public function space_settings_reject_duplicate_blocks_within_one_named_sitemap(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemaps' => [
                    [
                        'slug' => 'pages',
                        'types' => [
                            ['block' => 'page', 'path' => 'meta'],
                            ['block' => 'page', 'path' => 'seo'],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settings.sitemaps.0.types']);
    }

    #[Test]
    public function the_same_block_may_appear_in_different_named_sitemaps(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemaps' => [
                    ['slug' => 'pages', 'types' => [['block' => 'page', 'path' => 'meta']]],
                    ['slug' => 'everything', 'types' => [['block' => 'page', 'path' => 'meta']]],
                ],
            ],
        ]);

        $response->assertOk();
    }

    #[Test]
    public function space_settings_reject_duplicate_blocks_and_empty_paths(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson(route('mgmt.spaces.update', $this->space), [
            'settings' => [
                'sitemap' => [
                    'types' => [
                        ['block' => 'page', 'path' => 'meta'],
                        ['block' => 'page', 'path' => ''],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'settings.sitemap.types.1.block',
            'settings.sitemap.types.1.path',
        ]);
    }
}
