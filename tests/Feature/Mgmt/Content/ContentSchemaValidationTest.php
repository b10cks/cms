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

class ContentSchemaValidationTest extends TestCase
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
            'schema' => [
                'toggle' => [
                    'type' => 'boolean',
                    'name' => 'Toggle',
                ],
                'headline' => [
                    'type' => 'text',
                    'name' => 'Headline',
                    'required' => true,
                    'conditions' => [
                        'mode' => 'all',
                        'rules' => [[
                            'field' => 'toggle',
                            'operator' => 'equals',
                            'value' => true,
                        ]],
                    ],
                ],
                'summary' => [
                    'type' => 'text',
                    'name' => 'Summary',
                    'required' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['toggle', 'headline', 'summary'],
            ]],
        ]);
    }

    #[Test]
    public function hidden_fields_are_pruned_before_the_version_is_saved(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Home',
            'slug' => 'home',
            'block_id' => $this->pageBlock->id,
            'language_iso' => 'en',
            'content' => [
                'toggle' => false,
                'headline' => 'Should be removed',
                'summary' => 'Visible summary',
            ],
        ]);

        $response->assertCreated();

        $content = Content::query()->firstOrFail()->load('current_version');

        $this->assertArrayNotHasKey('headline', $content->current_version->content);
        $this->assertSame('Visible summary', $content->current_version->content['summary']);
    }

    #[Test]
    public function visible_conditional_fields_are_validated(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Home',
            'slug' => 'home',
            'block_id' => $this->pageBlock->id,
            'language_iso' => 'en',
            'content' => [
                'toggle' => true,
                'summary' => 'Visible summary',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.headline']);
    }

    #[Test]
    public function current_and_published_version_switches_reject_invalid_historical_versions(): void
    {
        $this->actingAs($this->owner);

        $content = $this->createContent([
            'toggle' => false,
            'summary' => 'Visible summary',
        ]);

        $invalidVersion = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'parent_id' => $content->current_version_id,
            'content' => [
                'toggle' => true,
                'summary' => 'Visible summary',
            ],
            'created_by_id' => $this->owner->id,
        ]);

        $this->postJson(route('mgmt.contents.versions.current', [
            'space' => $this->space->id,
            'content' => $content->id,
            'version' => $invalidVersion->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.headline']);

        $this->postJson(route('mgmt.contents.versions.publish', [
            'space' => $this->space->id,
            'content' => $content->id,
            'version' => $invalidVersion->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.headline']);
    }

    #[Test]
    public function translation_conditionals_use_effective_overlay_values_and_clear_hidden_local_fields(): void
    {
        $this->actingAs($this->owner);

        $translationBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Promo Page',
            'slug' => 'promoPage',
            'type' => 'root',
            'schema' => [
                'status' => [
                    'type' => 'option',
                    'name' => 'Status',
                    'translatable' => true,
                    'options' => [
                        ['name' => 'Show', 'value' => 'show'],
                        ['name' => 'Hide', 'value' => 'hide'],
                    ],
                ],
                'promo_title' => [
                    'type' => 'text',
                    'name' => 'Promo Title',
                    'required' => true,
                    'translatable' => true,
                    'conditions' => [
                        'mode' => 'all',
                        'rules' => [[
                            'field' => 'status',
                            'operator' => 'equals',
                            'value' => 'show',
                        ]],
                    ],
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['status', 'promo_title'],
            ]],
        ]);

        $canonical = $this->createContent([
            'status' => 'show',
            'promo_title' => 'Default promo',
        ], $translationBlock, 'en');

        $createResponse = $this->postJson(route('mgmt.contents.store', [
            'space' => $this->space->id,
        ]), [
            'name' => 'Promo DE',
            'slug' => 'promo-de',
            'block_id' => $translationBlock->id,
            'language_iso' => 'de',
            'i18n_parent_id' => $canonical->id,
            'content' => [],
        ]);

        $createResponse->assertCreated();

        $translation = Content::query()
            ->where('i18n_parent_id', $canonical->id)
            ->where('language_iso', 'de')
            ->firstOrFail();

        $updateResponse = $this->patchJson(route('mgmt.contents.update', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'content' => [
                'status' => 'hide',
                'promo_title' => 'Should be removed',
            ],
        ]);

        $updateResponse->assertOk();

        $translation->refresh()->load('current_version');

        $this->assertSame('hide', $translation->current_version->content['status']);
        $this->assertArrayNotHasKey('promo_title', $translation->current_version->content);
    }

    #[Test]
    public function publishing_a_localized_overlay_ignores_missing_non_translatable_fields(): void
    {
        $this->actingAs($this->owner);

        $translationBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Localized Page',
            'slug' => 'localizedPage',
            'type' => 'root',
            'schema' => [
                'media' => [
                    'type' => 'asset',
                    'name' => 'Media',
                    'required' => true,
                ],
                'headline' => [
                    'type' => 'text',
                    'name' => 'Headline',
                    'required' => true,
                    'translatable' => true,
                ],
            ],
            'editor' => [[
                'header' => 'General',
                'items' => ['media', 'headline'],
            ]],
        ]);

        $canonical = $this->createContent([
            'headline' => 'Default headline',
        ], $translationBlock, 'en');

        $translation = $this->createContent([
            'media' => null,
            'headline' => 'Lokalisierte Headline',
        ], $translationBlock, 'de', $canonical);

        $this->postJson(route('mgmt.contents.publish', [
            'space' => $this->space->id,
            'content' => $translation->id,
        ]), [
            'content' => [
                'headline' => 'Lokalisierte Headline',
            ],
        ])->assertOk();

        $translation->refresh()->load('published_version');

        $this->assertNotNull($translation->published_at);
        $this->assertSame(
            ['headline' => 'Lokalisierte Headline'],
            $translation->published_version?->content
        );
    }

    protected function createContent(
        array $contentData,
        ?Block $block = null,
        string $languageIso = 'en',
        ?Content $i18nParent = null,
    ): Content {
        $content = new Content;
        $content->forceFill([
            'block_id' => ($block ?? $this->pageBlock)->id,
            'name' => 'Page',
            'slug' => strtolower((string) Str::random(8)),
            'full_slug' => '/' . strtolower((string) Str::random(8)),
            'language_iso' => $languageIso,
            'i18n_parent_id' => $i18nParent?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $contentData,
            'created_by_id' => $this->owner->id,
        ]);

        $content->current_version_id = $version->id;
        $content->save();

        return $content->fresh();
    }
}
